<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Utils\Helper;

class V2boardAdminCreate extends Command
{
    protected $signature = 'v2board:admin-create
                            {--email= : 管理员邮箱}
                            {--password= : 管理员密码（最少8位）}';
    protected $description = '创建管理员账号（非交互式，Docker 专用）';

    public function handle()
    {
        $email = $this->option('email');
        $password = $this->option('password');

        if (empty($email)) {
            $this->error('必须指定 --email');
            return 1;
        }

        if (empty($password) || strlen($password) < 8) {
            $this->error('--password 必须至少 8 位');
            return 1;
        }

        $existing = User::where('email', $email)->first();
        if ($existing) {
            $this->info("管理员 {$email} 已存在，跳过创建");
            return 0;
        }

        $user = new User();
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->is_admin = 1;

        if (!$user->save()) {
            $this->error('管理员创建失败');
            return 1;
        }

        $this->info("管理员 {$email} 创建成功");
        return 0;
    }
}
