<?php
declare(strict_types=1);

/**
 * An orphan is a local image whose ID is not the Image of any container,
 * running or stopped, that docker itself lists.
 */
final class Orphan
{
    /** @var string */
    public $id = '';
    /** @var string */
    public $shortId = '';
    /** @var array<int,string> */
    public $tags = [];
    /** @var array<int,string> */
    public $digests = [];
    /** @var string repository@shortdigest shown for untagged rows */
    public $digestLabel = '';
    /** @var string pinned|template|compose|tagged|untagged */
    public $class = 'untagged';
    /** @var string */
    public $classLabel = 'Untagged';
    /** @var bool pre-ticked in the UI */
    public $preselect = true;
    /** @var bool */
    public $hasChildren = false;
    /** @var int epoch seconds */
    public $created = 0;
    /** @var int days since creation */
    public $ageDays = 0;
    /** @var int bytes */
    public $size = 0;
    /** @var int|null bytes shared with other images (from /system/df); null if unknown */
    public $sharedSize = null;
    /** @var int|null size minus shared layers; null if SharedSize is unavailable */
    public $uniqueSize = null;
    /** @var array<int,string> */
    public $reasons = [];

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'shortId'     => $this->shortId,
            'tags'        => array_values($this->tags),
            'digests'     => array_values($this->digests),
            'digestLabel' => $this->digestLabel,
            'class'       => $this->class,
            'classLabel'  => $this->classLabel,
            'preselect'   => $this->preselect,
            'hasChildren' => $this->hasChildren,
            'created'     => $this->created,
            'ageDays'     => $this->ageDays,
            'size'        => $this->size,
            'sharedSize'  => $this->sharedSize,
            'uniqueSize'  => $this->uniqueSize,
            'humanSize'   => Orphans::humanBytes($this->size),
            'humanUniqueSize' => $this->uniqueSize === null ? null : Orphans::humanBytes($this->uniqueSize),
            'reasons'     => array_values($this->reasons),
        ];
    }
}

final class Orphans
{
    public const TEMPLATES_DIR = '/boot/config/plugins/dockerMan/templates-user';
    public const COMPOSE_PLUGIN_DIR = '/boot/config/plugins/compose.manager';
    public const MAX_IMAGES_FOR_CHILD_SCAN = 400;

    /** @var DockerApi */
    private $api;
    /** @var Config */
    private $cfg;

    public function __construct(DockerApi $api, Config $cfg)
    {
        $this->api = $api;
        $this->cfg = $cfg;
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes < 1000) {
            return $bytes . ' B';
        }
        $units = ['kB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $bytes;
        $unit = 'B';
        foreach ($units as $candidate) {
            $value /= 1000.0;
            $unit = $candidate;
            if ($value < 1000.0) {
                break;
            }
        }
        return number_format($value, $value < 10 ? 2 : 1) . ' ' . $unit;
    }

    /**
     * Normalise a docker reference to repo:tag form so template/compose
     * references and image tags can be compared.
     */
    public static function normalizeRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            return '';
        }
        $at = strpos($ref, '@');
        if ($at !== false) {
            $ref = substr($ref, 0, $at);
        }
        if ($ref === '') {
            return '';
        }
        $slash = strrpos($ref, '/');
        $colon = strrpos($ref, ':');
        $hasTag = ($colon !== false && ($slash === false || $colon > $slash));
        if (!$hasTag) {
            $ref .= ':latest';
        }
        return $ref;
    }

    /**
     * @param array<int,mixed> $tags
     * @return array<int,string>
     */
    private static function cleanTags(array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            if (!is_string($tag) || $tag === '' || $tag === '<none>:<none>') {
                continue;
            }
            $out[] = $tag;
        }
        return $out;
    }

    /**
     * @param array<int,mixed> $digests
     * @return array<int,string>
     */
    private static function cleanDigests(array $digests): array
    {
        $out = [];
        foreach ($digests as $digest) {
            if (is_string($digest) && $digest !== '') {
                $out[] = $digest;
            }
        }
        return $out;
    }

    /**
     * Compute the full orphan report.
     *
     * @return array<string,mixed>
     */
    public function compute(): array
    {
        // Candidate set: the daemon's top-level image list (all=0), which is
        // what the Unraid Docker page enumerates. It includes superseded pulls
        // (no RepoTags, still carrying RepoDigests) - exactly the images a user
        // needs to clean up. The previous digest-only exclusion hid them.
        $candidates = $this->api->listImages(false);
        // all=1 additionally exposes intermediate images, needed only to decide
        // whether a candidate has children (and so will refuse to be removed).
        $all = $this->api->listImages(true);
        $containers = $this->api->listContainers(true);

        $referenced = [];
        foreach ($containers as $container) {
            if (!empty($container['ImageID']) && is_string($container['ImageID'])) {
                $referenced[$container['ImageID']] = true;
            }
        }

        // SharedSize is only populated by /system/df, not by /images/json. It
        // lets us report how much of an image's Size is not shared with others.
        $df = [];
        try {
            $df = $this->api->systemDf();
        } catch (DockerApiException $e) {
            $df = [];
        }
        $sharedSizes = [];
        if (isset($df['Images']) && is_array($df['Images'])) {
            foreach ($df['Images'] as $entry) {
                if (is_array($entry) && isset($entry['Id']) && array_key_exists('SharedSize', $entry)) {
                    $sharedSizes[(string) $entry['Id']] = (int) $entry['SharedSize'];
                }
            }
        }

        $report = self::buildReport(
            $candidates,
            $referenced,
            $this->computeChildren($all),
            $this->templateRepositories(),
            $this->composeImages(),
            $this->cfg->pinPatterns,
            null,
            $sharedSizes
        );
        $report['buildCache'] = self::buildCacheFromDf($df);
        return $report;
    }

    /**
     * Pure classifier: no daemon or filesystem access, so it can be exercised
     * with canned /images/json and /containers/json payloads.
     *
     * @param array<int,array<string,mixed>> $images        candidate images (all=0)
     * @param array<string,bool>             $referenced    image ids in use by containers
     * @param array<string,bool>             $children      image ids that have children
     * @param array<string,string>           $templateRepos normalized repo:tag => file
     * @param array<string,string>           $composeImages normalized repo:tag => file
     * @param array<int,string>              $pins
     * @param array<string,int>              $sharedSizes    image id => SharedSize bytes
     * @return array<string,mixed>
     */
    public static function buildReport(
        array $images,
        array $referenced,
        array $children,
        array $templateRepos,
        array $composeImages,
        array $pins,
        ?int $now = null,
        array $sharedSizes = []
    ): array {
        $now = $now ?? time();
        $orphans = [];
        $seen = [];
        $totals = [
            'count'         => 0,
            'size'          => 0,
            'uniqueSize'    => 0,
            'preselect'     => 0,
            'preselectSize' => 0,
            'classes'       => [
                'pinned'   => 0,
                'template' => 0,
                'compose'  => 0,
                'tagged'   => 0,
                'untagged' => 0,
            ],
        ];

        foreach ($images as $image) {
            $id = isset($image['Id']) ? (string) $image['Id'] : '';
            if ($id === '' || isset($seen[$id]) || isset($referenced[$id])) {
                continue;
            }
            $seen[$id] = true;

            $tags = self::cleanTags((array) ($image['RepoTags'] ?? []));
            $digests = self::cleanDigests((array) ($image['RepoDigests'] ?? []));

            $orphan = new Orphan();
            $orphan->id = $id;
            $orphan->shortId = (strpos($id, 'sha256:') === 0) ? substr($id, 7, 12) : substr($id, 0, 12);
            $orphan->tags = $tags;
            $orphan->digests = $digests;
            $orphan->created = (int) ($image['Created'] ?? 0);
            $orphan->size = (int) ($image['Size'] ?? 0);
            if (array_key_exists($id, $sharedSizes)) {
                $orphan->sharedSize = (int) $sharedSizes[$id];
                if ($orphan->sharedSize >= 0) {
                    $orphan->uniqueSize = max(0, $orphan->size - $orphan->sharedSize);
                }
            }
            $orphan->hasChildren = isset($children[$id]);
            $orphan->ageDays = $orphan->created > 0 ? (int) floor(max(0, $now - $orphan->created) / 86400) : 0;
            if (count($tags) === 0 && count($digests) > 0) {
                $orphan->digestLabel = self::digestLabel($digests[0]);
            }

            self::classifyOrphan($orphan, $templateRepos, $composeImages, $pins);

            $orphans[] = $orphan;
            $totals['count']++;
            $totals['size'] += $orphan->size;
            if ($orphan->uniqueSize !== null) {
                $totals['uniqueSize'] += $orphan->uniqueSize;
            }
            $totals['classes'][$orphan->class]++;
            if ($orphan->preselect) {
                $totals['preselect']++;
                $totals['preselectSize'] += $orphan->size;
            }
        }

        usort($orphans, static function (Orphan $a, Orphan $b): int {
            $order = ['untagged' => 0, 'tagged' => 1, 'compose' => 2, 'template' => 3, 'pinned' => 4];
            $oa = $order[$a->class] ?? 9;
            $ob = $order[$b->class] ?? 9;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            return strcmp($a->shortId, $b->shortId);
        });

        return [
            'orphans'   => array_map(static function (Orphan $o): array { return $o->toArray(); }, $orphans),
            'totals'    => $totals,
            'templates' => array_keys($templateRepos),
            'compose'   => array_keys($composeImages),
            'generated' => $now,
        ];
    }

    /**
     * Build a short "repository@sha256:xxxxxxxxxxxx" label from a digest, so an
     * untagged row still tells the user what the image was.
     */
    public static function digestLabel(string $digest): string
    {
        $at = strpos($digest, '@');
        if ($at === false) {
            return $digest;
        }
        $repo = substr($digest, 0, $at);
        $rest = substr($digest, $at + 1);
        $colon = strpos($rest, ':');
        if ($colon === false) {
            return $digest;
        }
        $algo = substr($rest, 0, $colon);
        $hex = substr($rest, $colon + 1);
        return $repo . '@' . $algo . ':' . substr($hex, 0, 12);
    }

    /**
     * @param array<string,string> $templateRepos normalised repo:tag => file
     * @param array<string,string> $composeImages normalised repo:tag => file
     * @param array<int,string>    $pins
     */
    private static function classifyOrphan(Orphan $orphan, array $templateRepos, array $composeImages, array $pins): void
    {
        $pin = self::matchesPin($orphan, $pins);
        if ($pin !== null) {
            $orphan->class = 'pinned';
            $orphan->classLabel = 'Pinned';
            $orphan->preselect = false;
            $orphan->reasons[] = 'matches pin pattern: ' . $pin;
            return;
        }

        $template = self::matchReference($orphan->tags, $templateRepos);
        if ($template !== null) {
            $orphan->class = 'template';
            $orphan->classLabel = 'Template';
            $orphan->preselect = false;
            $orphan->reasons[] = 'referenced by template ' . $template;
            return;
        }

        $compose = self::matchReference($orphan->tags, $composeImages);
        if ($compose !== null) {
            $orphan->class = 'compose';
            $orphan->classLabel = 'Compose';
            $orphan->preselect = false;
            $orphan->reasons[] = 'referenced by ' . $compose;
            return;
        }

        if (count($orphan->tags) > 0) {
            $orphan->class = 'tagged';
            $orphan->classLabel = 'Tagged';
            $orphan->preselect = false;
            return;
        }

        $orphan->class = 'untagged';
        $orphan->classLabel = 'Untagged';
        $orphan->preselect = true;
    }

    /**
     * @param array<int,string>    $tags
     * @param array<string,string> $references
     */
    private static function matchReference(array $tags, array $references): ?string
    {
        foreach ($tags as $tag) {
            $normalised = self::normalizeRef($tag);
            if ($normalised !== '' && isset($references[$normalised])) {
                return $references[$normalised];
            }
        }
        return null;
    }

    /**
     * @param array<int,string> $pins
     */
    private static function matchesPin(Orphan $orphan, array $pins): ?string
    {
        if (count($pins) === 0) {
            return null;
        }
        $candidates = array_merge($orphan->tags, $orphan->digests, [$orphan->id, $orphan->shortId]);
        foreach ($pins as $pin) {
            foreach ($candidates as $candidate) {
                if ($candidate === '') {
                    continue;
                }
                if (fnmatch($pin, $candidate, FNM_CASEFOLD)) {
                    return $pin;
                }
            }
        }
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $images
     * @return array<string,bool> set of image ids that have children
     */
    private function computeChildren(array $images): array
    {
        $children = [];
        $count = count($images);

        foreach ($images as $image) {
            if (!empty($image['ParentId']) && is_string($image['ParentId'])) {
                $children[$image['ParentId']] = true;
            }
        }

        if ($count === 0 || $count > self::MAX_IMAGES_FOR_CHILD_SCAN) {
            return $children;
        }

        // The Engine API omits ParentId on some storage drivers, so also treat
        // an image whose layer list is a strict prefix of another image's layer
        // list as a parent.
        $layers = [];
        foreach ($images as $image) {
            $id = isset($image['Id']) ? (string) $image['Id'] : '';
            if ($id === '') {
                continue;
            }
            try {
                $detail = $this->api->inspectImage($id);
                $layers[$id] = isset($detail['RootFS']['Layers']) && is_array($detail['RootFS']['Layers'])
                    ? array_values($detail['RootFS']['Layers'])
                    : [];
            } catch (DockerApiException $e) {
                $layers[$id] = [];
            }
        }

        foreach ($layers as $id => $layerList) {
            if (count($layerList) === 0) {
                continue;
            }
            foreach ($layers as $otherId => $otherLayers) {
                if ($id === $otherId || count($layerList) >= count($otherLayers)) {
                    continue;
                }
                if (array_slice($otherLayers, 0, count($layerList)) === $layerList) {
                    $children[$id] = true;
                    break;
                }
            }
        }

        return $children;
    }

    /**
     * @return array<string,string> normalised repo:tag => template file path
     */
    private function templateRepositories(): array
    {
        $out = [];
        if (!is_dir(self::TEMPLATES_DIR)) {
            return $out;
        }
        $files = glob(self::TEMPLATES_DIR . '/*.xml');
        if (!is_array($files)) {
            return $out;
        }
        foreach ($files as $file) {
            $repo = $this->readTemplateRepository($file);
            if ($repo !== '') {
                $out[self::normalizeRef($repo)] = $file;
            }
        }
        return $out;
    }

    private function readTemplateRepository(string $file): string
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml !== false && isset($xml->Repository)) {
            return trim((string) $xml->Repository);
        }

        $raw = @file_get_contents($file);
        if (is_string($raw) && preg_match('#<Repository>\s*([^<]+?)\s*</Repository>#i', $raw, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * @return array<string,string> normalised repo:tag => compose file path
     */
    private function composeImages(): array
    {
        $out = [];
        if (!is_dir(self::COMPOSE_PLUGIN_DIR)) {
            return $out;
        }
        $root = $this->composeProjectRoot();
        foreach ($this->findComposeFiles($root, 3) as $file) {
            $raw = @file_get_contents($file);
            if (!is_string($raw)) {
                continue;
            }
            foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
                if (preg_match('/^\s*image:\s*[\'"]?([^\'"\s#]+)/', $line, $m)) {
                    $ref = self::normalizeRef($m[1]);
                    if ($ref !== '') {
                        $out[$ref] = $file;
                    }
                }
            }
        }
        return $out;
    }

    private function composeProjectRoot(): string
    {
        $default = self::COMPOSE_PLUGIN_DIR . '/projects';
        $files = glob(self::COMPOSE_PLUGIN_DIR . '/*.cfg');
        if (is_array($files)) {
            foreach ($files as $cfgFile) {
                $ini = @parse_ini_file($cfgFile, false, INI_SCANNER_RAW);
                if (!is_array($ini)) {
                    continue;
                }
                foreach (['PROJECTS_DIR', 'COMPOSE_DIR', 'COMPOSE_PROJECTS_DIR', 'PROJECTDIR', 'projects_dir', 'DIR'] as $key) {
                    if (!empty($ini[$key])) {
                        $candidate = rtrim((string) $ini[$key], '/');
                        if (is_dir($candidate)) {
                            return $candidate;
                        }
                    }
                }
            }
        }
        return is_dir($default) ? $default : self::COMPOSE_PLUGIN_DIR;
    }

    /**
     * @return array<int,string>
     */
    private function findComposeFiles(string $root, int $depth): array
    {
        $out = [];
        if ($depth < 0 || !is_dir($root)) {
            return $out;
        }
        $handle = @opendir($root);
        if ($handle === false) {
            return $out;
        }
        $names = ['docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml'];
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root . '/' . $entry;
            if (is_dir($path)) {
                foreach ($this->findComposeFiles($path, $depth - 1) as $nested) {
                    $out[] = $nested;
                }
            } elseif (in_array(strtolower($entry), $names, true)) {
                $out[] = $path;
            }
        }
        closedir($handle);
        return $out;
    }

    /**
     * @param array<string,mixed> $df /system/df payload
     * @return array{reclaimable:int,items:int}
     */
    public static function buildCacheFromDf(array $df): array
    {
        $result = ['reclaimable' => 0, 'items' => 0];
        if (!isset($df['BuildCache']) || !is_array($df['BuildCache'])) {
            return $result;
        }
        foreach ($df['BuildCache'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $result['items']++;
            if (empty($entry['InUse'])) {
                $result['reclaimable'] += (int) ($entry['Size'] ?? 0);
            }
        }
        return $result;
    }
}
