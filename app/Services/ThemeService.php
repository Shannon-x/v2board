<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class ThemeService
{
    private $path;
    private $theme;

    private function updateCachedConfig(string $dotKey, $value): void
    {
        $cacheFile = base_path('bootstrap/cache/config.php');
        if (!file_exists($cacheFile)) return;
        $cached = require $cacheFile;
        data_set($cached, $dotKey, $value);
        file_put_contents($cacheFile, '<?php return ' . var_export($cached, true) . ';' . PHP_EOL, LOCK_EX);
    }

    public function __construct($theme)
    {
        $this->theme = $theme;
        $this->path = $path = public_path('theme/');
    }

    public function init()
    {
        $themeConfigFile = $this->path . "{$this->theme}/config.json";
        if (!File::exists($themeConfigFile)) abort(500, "{$this->theme}主题不存在");
        $themeConfig = json_decode(File::get($themeConfigFile), true);
        if (!isset($themeConfig['configs']) || !is_array($themeConfig)) abort(500, "{$this->theme}主题配置文件有误");
        $configs = $themeConfig['configs'];
        $data = [];
        foreach ($configs as $config) {
            $data[$config['field_name']] = isset($config['default_value']) ? $config['default_value'] : '';
        }

        $data = var_export($data, 1);
        try {
            if (!File::put(base_path() . "/config/theme/{$this->theme}.php", "<?php\n return $data ;")) {
                abort(500, "{$this->theme}初始化失败");
            }
        } catch (\Exception $e) {
            abort(500, '请检查V2Board目录权限');
        }

        $freshConfig = include base_path("config/theme/{$this->theme}.php");
        app('config')->set("theme.{$this->theme}", $freshConfig);
        $this->updateCachedConfig("theme.{$this->theme}", $freshConfig);
    }
}
