<?php

namespace TomLutzenberger\ResponsiveImage\components;

use TomLutzenberger\ResponsiveImage\models\Preset;
use Yii;
use yii\base\Component;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;
use yii\imagine\Image;

/**
 * Class ResponsiveImage
 *
 * @package   TomLutzenberger\ResponsiveImage\components
 * @copyright 2019 Tom Lutzenberger
 * @author    Tom Lutzenberger <lutzenbergertom@gmail.com>
 */
class ResponsiveImage extends Component
{
    /**
     * @var boolean Whether caching should be enabled or not. Default is true
     */
    public $cacheEnabled = true;

    /**
     * @var boolean Whether caching should be enabled or not. Default is 30 days
     */
    public $cacheDuration = 2592000; // 86400 sec * 30 days;

    /**
     * @var boolean Whether cache busting should be enabled or not. Default is true
     */
    public $cacheBustingEnabled = true;

    /**
     * @var boolean Whether cache busting should be enabled or not. Default is true
     */
    public $defaultQuality = 80;

    /**
     * @var string The default path to store thumbnails at
     *
     * By using curly braces, you can use properties of the preset, e.g.
     * `@webroot/thumbnails/{width}x{height}x{quality}` which would result in a directory like `/640x480x80`
     */
    public $defaultTargetPath = '@webroot/thumbnails/{name}';

    /**
     * @var array An array of presets
     * @example ```
     *    'preset-name' => [
     *        'srcPath'         => '@web/img/some_path',
     *        'targetPath'      => '@web/img/some_path/480x400',
     *        'targetExtension' => 'jpg',
     *        'width'           => 480,
     *        'height'          => 400,
     *        'quality'         => 80,
     *        'breakpointMin'   => 992,
     *        'breakpointMax'   => 1200,
     *    ],
     * ```
     */
    public $presets = [];

    /**
     * @var Preset[] An array of preset models
     */
    protected $presetModels = [];

    /**
     * Check if presets have already been set up and do so if not.
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    protected function checkPresets()
    {
        if (count($this->presetModels) > 0) {
            return;
        }

        foreach ($this->presets as $name => $preset) {
            $this->createPreset($name, $preset);
        }
    }


    /**
     * Get thumbnail for given source file and preset
     * If it does not exist, it will be created.
     *
     * @param string $file       Path of the source file
     * @param string $presetName Name of the preset
     * @param bool   $force      Force the thumbnail creation
     * @return string
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function getThumbnail($file, $presetName, $force = false)
    {
        $preset = $this->getPreset($presetName);

        $fileAbsolute = $this->getAbsolutePath($file);
        $imgInfo = $this->getImageInfo($fileAbsolute);

        if (!empty($preset->targetExtension) && $imgInfo['ext'] !== $preset->targetExtension) {
            $targetFileName = $imgInfo['filename'] . '.' . $preset->targetExtension;
        } else {
            $targetFileName = $imgInfo['filename'] . '.' . $imgInfo['ext'];
        }

        $targetAbsolute = $this->getAbsolutePath($preset->targetPath . '/' . $targetFileName, false);
        $targetFile = $this->getRelativePath($preset->targetPath . '/' . $targetFileName);

        // Recreate the thumbnail if the image is newer than the thumb.
        if ($imgInfo['modified'] > \filemtime($targetAbsolute)) {
            $force = true;
        }

        if ($force || !file_exists($targetAbsolute)) {
            $this->createThumbnail($fileAbsolute, $targetAbsolute, $presetName);
        }

        return $targetFile . ($this->cacheBustingEnabled && $preset->cacheBusting ? '?v=' . $imgInfo['modified'] : '');
    }


    /**
     * Get specific preset model by name
     *
     * @param string $presetName
     * @return Preset
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function getPreset($presetName)
    {
        $this->checkPresets();
        if (!array_key_exists($presetName, $this->presetModels)) {
            throw new InvalidArgumentException("Thumbnail preset `$presetName` does not exist.");
        }

        return $this->presetModels[$presetName];
    }


    /**
     * Get all preset models
     *
     * @return Preset[]
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function getPresets()
    {
        $this->checkPresets();
        return $this->presetModels;
    }

    /**
     * Create preset model with given configuration
     *
     * @param string $presetName
     * @param array  $config
     * @return Preset
     * @throws ErrorException
     * @throws InvalidConfigException
     * @throws Exception
     */
    public function createPreset($presetName, $config)
    {
        if (array_key_exists($presetName, $this->presetModels)) {
            throw new ErrorException("Thumbnail preset `$presetName` already exists and can't be overwritten.");
        }

        $config['name'] = $presetName;
        $model = new Preset($config);
        if (!$model->validate()) {
            $errors = [];
            foreach ($model->getErrors() as $eName => $eList) {
                foreach ($eList as $error) {
                    $errors[] = $eName . ': ' . $error;
                }
            }
            throw new InvalidConfigException("Thumbnail preset `$presetName` has invalid configuration: " . PHP_EOL . implode(PHP_EOL, $errors));
        }

        $this->presetModels[$presetName] = $model;
        $target = $this->getAbsolutePath($model->targetPath, false);
        if (!file_exists($target)) {
            FileHelper::createDirectory($target);
        }

        return $model;
    }


    /**
     * Create a new image thumbnail
     *
     * @param string $srcFile
     * @param string $targetFile
     * @param string $presetName
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    protected function createThumbnail($srcFile, $targetFile, $presetName)
    {
        $preset = $this->getPreset($presetName);
        $quality = $preset->quality > 0 ? $preset->quality : $this->defaultQuality;

        $i = Image::thumbnail($srcFile, $preset->width, $preset->height);
        $i->save($targetFile, ['quality' => $quality]);
        if (!empty($preset->pixelDensity)) {
            $pathInfo = pathinfo($targetFile);
            foreach($preset->pixelDensity as $pixelDensity) {
                $width = $preset->width ? $preset->width * $pixelDensity : null;
                $height = $preset->height ? $preset->height * $pixelDensity : null;
                $i = Image::thumbnail($srcFile, $width, $height);
                $i->save(
                    $pathInfo['dirname']
                        . '/'
                        . $pathInfo['filename']
                        . '-'
                        . $pixelDensity
                        . 'x.'
                        . $pathInfo['extension'],
                    ['quality' => $quality]
                );
            }
        }
    }

    /**
     * Read infos from given file
     *
     * @param string $file
     * @return array
     * @throws ErrorException
     */
    protected function getImageInfo($file)
    {
        if (!file_exists($file)) {
            throw new ErrorException("File `$file` does not exist.");
        }

        $fullInfo = [];

        if ($this->cacheEnabled) {
            $fullInfo = Yii::$app->cache->get(['file_info', $file]);
        }

        if (empty($fullInfo)) {
            $size = getimagesize($file);
            $info = pathinfo($file);

            $fullInfo = [
                'width'    => $size[0],
                'height'   => $size[1],
                'type'     => $size[2],
                'attr'     => $size[3],
                'mime'     => image_type_to_mime_type($size[2]),
                'modified' => filemtime($file),
                'dirname'  => $info['dirname'],
                'basename' => $info['basename'],
                'ext'      => $info['extension'],
                'filename' => $info['filename'],
            ];
        }

        if ($this->cacheEnabled && !empty($fullInfo)) {
            Yii::$app->cache->set(['file_info', $file], $fullInfo, $this->cacheDuration);
        }

        return $fullInfo;
    }

    /**
     * Get absolute path for given aliased path
     *
     * @param string $path
     * @param bool   $useRealPath
     * @return false|string
     */
    protected function getAbsolutePath($path, $useRealPath = true)
    {
        $abs = FileHelper::normalizePath(Yii::getAlias(str_replace('@web/', '@webroot/', $path)));
        return $useRealPath ? realpath($abs) : $abs;
    }

    /**
     * Get relative path for given aliased path
     *
     * @param string $path
     * @return string
     */
    protected function getRelativePath($path)
    {
        return FileHelper::normalizePath(Yii::getAlias(str_replace('@webroot/', '@web/', $path)));
    }

}
