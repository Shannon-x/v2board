<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class V2boardAdminPath extends Command
{
    protected $signature = 'v2board:admin-path';
    protected $description = '输出当前管理后台路径';

    public function handle()
    {
        $path = config(
            'v2board.secure_path',
            config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))
        );

        // 仅输出路径本身，方便脚本捕获
        $this->output->write($path);
        return 0;
    }
}
