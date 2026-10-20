<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Statamic\Facades\AssetContainer;

class AssetsResizeOriginals extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'assets:resize-originals
    {--max=2400 : Maximum width and height in pixels}
    {--dry-run : Only list the images that would be resized}';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Scales down original JPEG and PNG assets that are larger than --max (in place, same filename).';

  /**
   * Execute the console command.
   */
  public function handle()
  {
    ini_set('memory_limit', '-1');

    $max = (int) $this->option('max');
    $dryRun = $this->option('dry-run');
    $manager = ImageManager::usingDriver(Driver::class);
    $before = 0;
    $after = 0;
    $count = 0;

    $assets = AssetContainer::find('assets')->assets()->filter(function ($asset) use ($max) {
      return in_array(strtolower($asset->extension()), ['jpg', 'jpeg', 'png'])
        && ($asset->width() > $max || $asset->height() > $max);
    });

    $this->info(($dryRun ? 'Would resize ' : 'Resizing ') . $assets->count() . " images to max {$max}px...");

    foreach ($assets as $asset) {
      $path = $asset->resolvedPath();
      $size = filesize($path);
      $this->line("{$asset->path()} ({$asset->width()}x{$asset->height()}, " . round($size / 1e6, 1) . ' MB)');

      if ($dryRun) {
        continue;
      }

      // Write to a temp file first so a failure never leaves a broken original.
      $tmp = $path . '.resize-tmp.' . $asset->extension();
      $manager->decodePath($path)->scaleDown($max, $max)->save($tmp, quality: 92);
      rename($tmp, $path);
      clearstatcache(true, $path);

      // Refresh the asset metadata (dimensions, file size).
      Cache::forget($asset->metaCacheKey());
      $asset->writeMeta($asset->generateMeta());

      $before += $size;
      $after += filesize($path);
      $count++;
    }

    if (!$dryRun) {
      $this->info("Resized {$count} images: " . round($before / 1e6) . ' MB -> ' . round($after / 1e6) . ' MB.');
    }
  }
}
