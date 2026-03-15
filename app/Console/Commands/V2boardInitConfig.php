<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class V2boardInitConfig extends Command
{
    protected $signature = 'v2board:init-config
                            {--secure-path=admin-dashboard : 管理后台访问路径}';
    protected $description = '生成 config/v2board.php 初始配置（非交互式，Docker 专用）';

    public function handle()
    {
        $configPath = base_path('config/v2board.php');
        $securePath = $this->option('secure-path');

        $config = [];
        if (File::exists($configPath)) {
            $config = include $configPath;
            if (!is_array($config)) {
                $config = [];
            }
        }

        $previousSecurePath = $config['secure_path'] ?? null;
        $config['secure_path'] = $securePath;

        $data = var_export($config, true);
        File::put($configPath, "<?php\n return {$data} ;");

        if ($previousSecurePath === $securePath) {
            $this->info("config/v2board.php 已确认，secure_path = {$securePath}");
            return 0;
        }

        $this->info("config/v2board.php 已更新，secure_path = {$securePath}");
        return 0;
    }
}
