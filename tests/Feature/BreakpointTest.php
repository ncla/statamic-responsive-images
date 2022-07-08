<?php

namespace Spatie\ResponsiveImages\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\ResponsiveImages\Tests\TestCase;
use Spatie\ResponsiveImages\Breakpoint;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;

class BreakpointTest extends TestCase
{
    /** @var \Statamic\Assets\Asset */
    private $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::disk('test')->delete('*');

        $file = new UploadedFile($this->getTestJpg(), 'test.jpg');
        $path = ltrim('/'.$file->getClientOriginalName(), '/');
        $this->asset = $this->assetContainer->makeAsset($path)->upload($file);

        Stache::clear();
    }

    /** @test * */
    public function it_can_build_an_image()
    {
        $responsive = new Breakpoint($this->asset, 'default', 0, []);

        $this->assertStringContainsString(
            '?q=90&fit=crop-50-50&w=100',
            $responsive->buildImageJob(100)->handle()
        );
    }

    /** @test * */
    public function it_can_build_an_image_with_parameters()
    {
        $responsive = new Breakpoint($this->asset, 'default', 0, []);

        $this->assertStringContainsString(
            '?fm=webp&q=90&fit=crop-50-50&w=100',
            $responsive->buildImageJob(100, 'webp')->handle()
        );
    }

    /** @test * */
    public function it_can_build_an_image_with_a_ratio()
    {
        $responsive = new Breakpoint($this->asset, 'default', 0, []);

        $this->assertStringContainsString(
            '?fm=webp&q=90&fit=crop-50-50&w=100&h=100',
            $responsive->buildImageJob(100, 'webp', 1.0)->handle()
        );
    }

    /** @test * */
    public function it_doesnt_crash_with_a_null_ratio()
    {
        $this->expectNotToPerformAssertions();

        $breakpoint = new Breakpoint($this->asset, 'default', 0, [
            'ratio' => null,
        ]);

        $breakpoint->getSrcSet();
    }

    /** @test * */
    public function it_does_not_generate_image_url_with_crop_focus_when_auto_crop_is_disabled()
    {
        config()->set('statamic.assets.auto_crop', false);

        $breakpoint = new Breakpoint($this->asset, 'default', 0, []);

        $this->assertStringEndsWith(
            '?fm=webp&q=90&w=100&h=100',
            $breakpoint->buildImageJob(100, 'webp', 1.0)->handle()
        );
    }

    /** @test * */
    public function it_does_not_generate_image_url_with_crop_focus_when_a_glide_fit_param_is_provided()
    {
        $breakpoint = new Breakpoint($this->asset, 'default', 0, ['glide:fit' => 'fill']);

        $this->assertStringEndsWith(
            '?fit=fill&fm=webp&q=90&w=100&h=100',
            $breakpoint->buildImageJob(100, 'webp', 1.0)->handle()
        );
    }

    /** @test * */
    public function it_uses_crop_focus_value_from_assets_metadata()
    {
        $metaDataPath = $this->asset->metaPath();

        // Get original metadata that was generated when the asset was uploaded
        $metaData = YAML::file(
            Storage::disk('test')->path($metaDataPath)
        )->parse();

        // Set some focus value
        $metaData['data'] = [
            'focus' => '29-71-3.6'
        ];

        // Dump the YAML data back into the metadata yaml file
        Storage::disk('test')->put($metaDataPath, YAML::dump($metaData));

        // Flush the cache so Statamic is not using outdated metadata
        Cache::flush();

        // Fetch the asset from the container again, triggering metadata hydration
        $asset = $this->assetContainer->asset('test.jpg');

        $breakpoint = new Breakpoint($asset, 'default', 0, []);

        $this->assertStringEndsWith(
            '?q=90&fit=crop-29-71-3.6&w=100',
            $breakpoint->buildImageJob(100 )->handle()
        );
    }
}
