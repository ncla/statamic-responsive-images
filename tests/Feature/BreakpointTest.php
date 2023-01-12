<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Spatie\ResponsiveImages\Breakpoint;
use Statamic\Console\Commands\GlideClear;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;
use Statamic\Facades\Blink;

beforeEach(function () {
    Storage::disk('test')->delete('*');

    $file = new UploadedFile($this->getTestJpg(), 'test.jpg');
    $path = ltrim('/' . $file->getClientOriginalName(), '/');
    $this->asset = $this->assetContainer->makeAsset($path)->upload($file);
    Stache::clear();
});

it('can build an image', function () {
    $breakpoint = new Breakpoint($this->asset, 'default', 0, []);

    expect(
        $breakpoint->buildImageJob(100)->handle()
    )->toContain('?q=90&fit=crop-50-50&w=100');
});

it('can build an image with parameters', function () {
    $breakpoint = new Breakpoint($this->asset, 'default', 0, []);

    expect(
        $breakpoint->buildImageJob(100, null,'webp')->handle()
    )->toContain('?fm=webp&q=90&fit=crop-50-50&w=100');
});

it("doesn't crash with a `null` ratio", function () {
    $breakpoint = new Breakpoint($this->asset, 'default', 0, [
        'ratio' => null,
    ]);

    $breakpoint->getSrcSet();
})->expectNotToPerformAssertions();

it('does not generate image url with crop focus when auto crop is disabled', function () {
    config()->set('statamic.assets.auto_crop', false);

    $breakpoint = new Breakpoint($this->asset, 'default', 0, []);

    expect(
        $breakpoint->buildImageJob(100, 100, 'webp')->handle()
    )->toContain('?fm=webp&q=90&w=100&h=100',);
});

it('does not generate image url with crop focus when a `glide:fit` param is provided', function () {
    $breakpoint = new Breakpoint($this->asset, 'default', 0, ['glide:fit' => 'fill']);

    expect(
        $breakpoint->buildImageJob(100, 100, 'webp')->handle()
    )->toContain('?fit=fill&fm=webp&q=90&w=100&h=100');
});

it('uses crop focus value from assets metadata', function () {
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

    expect(
        $breakpoint->buildImageJob(100)->handle()
    )->toContain('?q=90&fit=crop-29-71-3.6&w=100');
});

it('generates placeholder data url when toggling cache form on to off', function () {
    /**
     * Clear regular cache and both Glide path cache storages
     * @see: https://statamic.dev/image-manipulation#path-cache-store
     */
    Config::set('statamic.assets.image_manipulation.cache', false);
    $this->artisan(GlideClear::class);
    Config::set('statamic.assets.image_manipulation.cache', true);
    $this->artisan(GlideClear::class);

    // Glide server has already initialized in service container, we clear it so the cache config value gets read.
    App::forgetInstance(\League\Glide\Server::class);

    $cacheDiskPathBefore = \Statamic\Facades\Glide::cacheDisk()->getConfig()['root'];

    // Generate placeholder
    $breakpoint = new Breakpoint($this->asset, 'default', 0, []);
    $firstPlaceholder = $breakpoint->placeholder();

    /**
     * We use Blink cache for placeholder generation that we need to clear just in case
     * @see https://statamic.dev/extending/blink-cache
     * @see Breakpoint::placeholder()
     */
    Blink::store()->flush();

    Config::set('statamic.assets.image_manipulation.cache', false);

    // Once again, because we are running in the same session, we need Glide server instance to be forgotten
    // so that it uses different Filesystem that depends on the statamic.assets.image_manipulation.cache value
    App::forgetInstance(\League\Glide\Server::class);

    $cacheDiskPathAfter = \Statamic\Facades\Glide::cacheDisk()->getConfig()['root'];

    // Generate placeholder again
    $breakpoint = new Breakpoint($this->asset, 'default', 0, []);
    $secondPlaceholder = $breakpoint->placeholder();

    expect($secondPlaceholder)->toEqual($firstPlaceholder)
        ->and($cacheDiskPathAfter)->not->toEqual($cacheDiskPathBefore);
});

it("doesn't crash when the placeholder image cannot be read", function () {
    $breakpoint = new Breakpoint($this->asset, 'default', 0, []);

    // Generate placeholder to trigger caching
    $breakpoint->placeholder();

    // Forget cached files
    $pathPrefix = \Statamic\Imaging\ImageGenerator::assetCachePathPrefix($this->asset);

    \Statamic\Facades\Glide::server()->deleteCache($pathPrefix.'/'.$this->asset->path());

    Blink::store()->flush();

    // Generate new placeholder
    $breakpoint->placeholder();
})->expectNotToPerformAssertions();

it('generates absolute url when using custom filesystem with custom url for glide cache', function () {
    config(['filesystems.disks.absolute_test' => [
        'driver' => 'local',
        'root' => __DIR__ . '/tmp',
        'url' => 'https://responsive.test/test',
    ]]);

    config([
        'statamic.assets.image_manipulation.cache' => 'absolute_test',
    ]);

    $breakpoint = new Breakpoint($this->asset, 'default', 0, []);

    expect(
        $breakpoint->buildImageJob(100)->handle()
    )->toStartWith('https://responsive.test/');
});

it('generates absolute url when force enabled through config', function () {
    config([
        'statamic.responsive-images.force_absolute_urls' => true,
    ]);

    $breakpoint = new Breakpoint($this->asset, 'default', 0, []);

    expect(
        $breakpoint->buildImageJob(100)->handle()
    )->toStartWith('http://localhost/');
});

it('generates relative url when absolute urls are disabled through config', function () {
    config([
        'statamic.responsive-images.force_absolute_urls' => false,
    ]);

    $breakpoint = new Breakpoint($this->asset, 'default', 0, []);

    expect(
        $breakpoint->buildImageJob(100)->handle()
    )->toStartWith('/img/asset/');
});