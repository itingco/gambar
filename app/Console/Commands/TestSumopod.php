<?php

namespace App\Console\Commands;

use App\Services\SumopodClient;
use Illuminate\Console\Command;
use Throwable;

class TestSumopod extends Command
{
    protected $signature = 'sumopod:test {--models : Tampilkan daftar model yang dikembalikan endpoint /v1/models}';
    protected $description = 'Tes koneksi API Sumopod dan model yang dikonfigurasi';

    public function handle(SumopodClient $sumopod): int
    {
        $this->line('Base URL : ' . config('image_ai.sumopod.base_url'));
        $this->line('Model    : ' . $sumopod->model());
        $this->line('Enabled  : ' . ($sumopod->enabled() ? 'YES' : 'NO'));

        try {
            if ($this->option('models')) {
                $models = $sumopod->availableModels();
                if (!$models) {
                    $this->warn('Endpoint models berhasil tetapi tidak mengembalikan model.');
                } else {
                    $this->newLine();
                    $this->info('Model tersedia:');
                    foreach ($models as $model) {
                        $this->line(' - ' . $model);
                    }
                }
                return self::SUCCESS;
            }

            $reply = $sumopod->testConnection();
            $this->newLine();
            $this->info('Koneksi Sumopod berhasil.');
            $this->line('Respons: ' . $reply);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Koneksi Sumopod gagal: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
