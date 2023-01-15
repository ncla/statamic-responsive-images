<?php

namespace Spatie\ResponsiveImages;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use League\Flysystem\FilesystemException;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Blink;
use Statamic\Facades\Glide as GlideManager;
use Statamic\Imaging\ImageGenerator;
use Statamic\Support\Str;

class Breakpoint implements Arrayable
{
    /** @var \Statamic\Assets\Asset */
    public $asset;

    /** @var string */
    public $label;

    /**
     * @var int The minimum width of when the breakpoint starts
     */
    public $minWidth;

    /** @var array */
    public $breakpointParams;

    /** @var string */
    public $widthUnit;

    public function __construct(Asset $asset, string $label, int $breakpointMinWidth, array $breakpointParams)
    {
        $this->asset = $asset;
        $this->label = $label;
        $this->minWidth = $breakpointMinWidth;
        $this->breakpointParams = $breakpointParams;
        $this->widthUnit = config('statamic.responsive-images.breakpoint_unit', 'px');
    }

    /**
     * TODO: Investigate if this is not being called too often and maybe implement some caching
     * @return Collection<Source>
     */
    public function getSources(): Collection
    {
        $formats = collect(['avif', 'webp', 'original']);

        $breakpointParams = $this->breakpointParams;

        return $formats->filter(function ($format) use($breakpointParams) {
            if ($format === 'original') return true;

            if (isset($breakpointParams[$format])) return $breakpointParams[$format];

            if(config('statamic.responsive-images.' . $format, false)) return true;

            return false;
        })->map(function ($format) {
            return new Source($this, $format);
        });
    }

    /**
     * Get only Glide params.
     *
     * @param string|null $format
     * @return array
     */
    public function getImageManipulationParams(string $format = null): array
    {
        $params = $this->getGlideParams();

        if ($format && $format !== 'original') {
            $params['fm'] = $format;
        }

        $quality = $this->getFormatQuality($format);

        if ($quality) {
            $params['q'] = $quality;
        }

        $crop = $this->getCropFocus($params);

        if ($crop) {
            $params['fit'] = $crop;
        }

        // There are two ways to pass in width, so we just use one: "width"
        if (isset($params['w'])) {
            $params['width'] = $params['width'] ?? $params['w'];
            unset($params['w']);
        }

        // Same for height
        if (isset($params['h'])) {
            $params['height'] = $params['height'] ?? $params['height'];
            unset($params['h']);
        }

        return $params;
    }

    private function getCropFocus($params): string|null
    {
        if (
            Config::get('statamic.assets.auto_crop') === false
            || (array_key_exists('fit', $params) && $params['fit'] !== 'crop_focal')
        ) {
            return null;
        }

        return "crop-" . $this->asset->get('focus', '50-50');
    }

    /**
     * Get format quality by the following order: glide parameter, quality parameter and then config values.
     *
     * @param string|null $format
     * @return int|null
     */
    private function getFormatQuality(string $format = null): int|null
    {
        if ($format === 'original') {
            $format = null;
        }

        // Backwards compatible if someone used glide:quality to adjust quality
        $glideParamsQualityValue = $this->breakpointParams['glide:quality'] ?? $this->breakpointParams['glide:q'] ?? null;

        if ($glideParamsQualityValue) {
            return intval($glideParamsQualityValue);
        }

        if ($format === null) {
            $format = $this->asset->extension();
        }

        if (isset($this->breakpointParams['quality:' . $format])) {
            return intval($this->breakpointParams['quality:' . $format]);
        }

        $configQualityValue = config('statamic.responsive-images.quality.' . $format);

        if ($configQualityValue !== null) {
            return intval($configQualityValue);
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'asset' => $this->asset,
            'label' => $this->label,
            'minWidth' => $this->minWidth,
            'widthUnit' => $this->widthUnit,
            'parameters' => $this->breakpointParams,
        ];
    }

    private function getGlideParams(): array
    {
        return collect($this->breakpointParams)
            ->filter(function ($value, $name) {
                return Str::contains($name, 'glide:');
            })
            ->mapWithKeys(function ($value, $name) {
                return [str_replace('glide:', '', $name) => $value];
            })
            ->toArray();
    }

    public function toGql(array $args): array
    {
        $data = [
            'asset' => $this->asset,
            'label' => $this->label,
            'minWidth' => $this->minWidth,
            'widthUnit' => $this->widthUnit,
            'sources' => $this->getSources()->map(function (Source $source) use($args) {
                return $source->toGql($args);
            })->all(),
            // TODO: There is no neat way to separate placeholder string from srcset string,
            // TODO: cause placeholder argument affects both.
            'placeholder' => $args['placeholder'] ? $this->placeholder() : null
        ];

        // Check if DimensionCalculator is instance of ResponsiveDimensionCalculator
        // as ratio is only property applicable only for this DimensionCalculator
        if (app(DimensionCalculator::class) instanceof ResponsiveDimensionCalculator) {
            $data['ratio'] = app(DimensionCalculator::class)->breakpointRatio($this->asset, $this);
        }

        return $data;
    }

    public function placeholder(): string
    {
        $dimensions = app(DimensionCalculator::class)
            ->calculateForPlaceholder($this->asset, $this);

        $blinkKey = "placeholder-{$this->asset->id()}-{$this->asset->id()}-{$dimensions->width}-{$dimensions->height}";

        return Blink::once($blinkKey, function () use($dimensions) {
            $imageGenerator = app(ImageGenerator::class);

            $params = [
                'w' => $dimensions->getWidth(),
                'h' => $dimensions->getHeight(),
                'blur' => 5,
                // Arbitrary parameter to change md5 hash for Glide manipulation cache key
                // to force Glide to generate new manipulated image if cache setting changes.
                // TODO: Remove this line once the issue has been resolved in statamic/cms package
                'cache' => Config::get('statamic.assets.image_manipulation.cache', false),
            ];

            $manipulationPath = $imageGenerator->generateByAsset($this->asset, $params);

            $base64Image = $this->readImageToBase64($manipulationPath);

            if (! $base64Image) {
                return '';
            }

            $placeholderSvg = view('responsive-images::placeholderSvg', [
                'width' => $dimensions->getWidth(),
                'height' => $dimensions->getHeight(),
                'image' => $base64Image,
                'asset' => $this->asset->toAugmentedArray(),
            ])->render();

            return 'data:image/svg+xml;base64,' . base64_encode($placeholderSvg);
        });
    }

    public function placeholderSrc(): string
    {
        $placeholder = $this->placeholder();

        if (empty($placeholder)) {
            return '';
        }

        return $placeholder . ' 32w';
    }

    private function readImageToBase64($assetPath): string|null
    {
        /**
         * Glide tag has undocumented method for generating data URL that we borrow from
         * @see \Statamic\Tags\Glide::generateGlideDataUrl
         */
        $cache = GlideManager::cacheDisk();

        try {
            $assetContent = $cache->read($assetPath);
            $assetMimeType = $cache->mimeType($assetPath);
        } catch (FilesystemException $e) {
            if (config('app.debug')) {
                throw $e;
            }

            logger()->error($e->getMessage());

            return null;
        }

        return 'data:' . $assetMimeType . ';base64,' . base64_encode($assetContent);
    }
}
