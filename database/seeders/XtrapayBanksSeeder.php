<?php

namespace Database\Seeders;

use App\Models\Xtrapay\XtrapayBank;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class XtrapayBanksSeeder extends Seeder
{
    private const NUBAN_BANK_CODES_URL = 'https://app.nuban.com.ng/bank_codes.json';

    public function run(): void
    {
        $rows = $this->fetchNubanBanks();

        if ($rows === []) {
            $this->command?->warn('NUBAN bank list empty/unavailable — keeping existing xtrapay_banks rows.');

            return;
        }

        $now = now();
        $payload = [];

        foreach ($rows as $row) {
            $code = trim((string) ($row['code'] ?? $row['bank_code'] ?? ''));
            $name = trim((string) ($row['bank_name'] ?? $row['name'] ?? ''));

            if ($code === '' || $name === '') {
                continue;
            }

            $id = Str::lower($code);

            $payload[$id] = [
                'id' => $id,
                'name' => $name,
                'code' => $code,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        XtrapayBank::query()->delete();

        foreach (array_chunk(array_values($payload), 200) as $chunk) {
            XtrapayBank::query()->insert($chunk);
        }

        $this->command?->info('Seeded '.count($payload).' banks from NUBAN ('.self::NUBAN_BANK_CODES_URL.').');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchNubanBanks(): array
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(30)
                ->acceptJson()
                ->get(self::NUBAN_BANK_CODES_URL);

            if (! $response->successful()) {
                $this->command?->error('NUBAN bank_codes.json HTTP '.$response->status());

                return [];
            }

            $data = $response->json();

            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            $this->command?->error('NUBAN fetch failed: '.$e->getMessage());

            return [];
        }
    }
}
