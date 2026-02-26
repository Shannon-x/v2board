<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class V2boardDbImport extends Command
{
    protected $signature = 'v2board:db-import';
    protected $description = '导入 install.sql 数据库结构（非交互式，Docker 专用）';

    public function handle()
    {
        $sqlFile = base_path('database/install.sql');
        if (!file_exists($sqlFile)) {
            $this->error('database/install.sql 不存在');
            return 1;
        }

        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $this->error('数据库连接失败: ' . $e->getMessage());
            return 1;
        }

        $tableCheck = DB::select("SHOW TABLES LIKE 'v2_user'");
        if (!empty($tableCheck)) {
            $this->info('v2_user 表已存在，跳过导入');
            return 0;
        }

        $sql = file_get_contents($sqlFile);
        $sql = str_replace("\n", "", $sql);
        $statements = preg_split("/;/", $sql);

        if (!is_array($statements)) {
            $this->error('SQL 文件格式有误');
            return 1;
        }

        $count = 0;
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement)) continue;
            try {
                DB::statement($statement);
                $count++;
            } catch (\Exception $e) {
                // CREATE TABLE IF NOT EXISTS 会跳过已有表
            }
        }

        $this->info("已执行 {$count} 条 SQL 语句");
        return 0;
    }
}
