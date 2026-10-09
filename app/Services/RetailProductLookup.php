<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RetailProductLookup
{
    public function itemCode(string $name): string
    {
        return trim((string) pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME));
    }

    /** Images under the top-level Ingco folder only use AS_INGCO; all other folders use AS_SMI. */
    public function connectionForPath(string $path): string
    {
        $segments = explode('/', trim(str_replace('\\', '/', $path), '/'));
        return strcasecmp($segments[0] ?? '', 'Ingco') === 0 ? 'sqlsrv_ingco' : 'sqlsrv_smi';
    }

    /**
     * @param array<int,array{id:string,name:string,path:string}> $results
     * @return array{products:array<string,array>,errors:array<string,string>}
     * products are keyed by Drive image ID, avoiding collisions for equal ItemCodes across DBs.
     */
    public function findForResults(array $results): array
    {
        $groups = ['sqlsrv_ingco' => [], 'sqlsrv_smi' => []];
        foreach ($results as $result) {
            $code = $this->itemCode($result['name']);
            if ($code === '') continue;
            $connection = $this->connectionForPath($result['path'] ?? '');
            $groups[$connection][$code] = true;
        }

        $foundByConnection = [];
        $errors = [];
        foreach ($groups as $connection => $codes) {
            if (!$codes) continue;
            $source = $connection === 'sqlsrv_ingco' ? 'AS_INGCO' : 'AS_SMI';
            try {
                foreach (array_chunk(array_keys($codes), 300) as $batch) {
                    $holders = implode(',', array_fill(0, count($batch), '?'));
                    $sql = "WITH ranked AS (
                        SELECT i.ItemCode, i.ItemName, ip.Price, pl.PriceLevelName,
                               ROW_NUMBER() OVER (
                                   PARTITION BY i.ItemCode ORDER BY ip.ValidFrom DESC, ip.Price DESC
                               ) AS rn
                        FROM IC_Items AS i
                        INNER JOIN IC_ItemPrices AS ip ON ip.ItemID = i.ItemID
                        INNER JOIN IC_PriceLevels AS pl ON pl.PriceLevelID = ip.PriceLevelID
                        WHERE ip.ValidFrom <= GETDATE()
                          AND pl.PriceLevelName = 'RETAIL'
                          AND i.ItemCode IN ($holders)
                    )
                    SELECT ItemCode, ItemName, Price, PriceLevelName FROM ranked WHERE rn = 1";
                    foreach (DB::connection($connection)->select($sql, $batch) as $row) {
                        $key = trim((string) $row->ItemCode);
                        $foundByConnection[$connection][$key] = [
                            'item_code' => $key,
                            'item_name' => (string) $row->ItemName,
                            'price' => $row->Price === null ? null : (float) $row->Price,
                            'price_level' => (string) $row->PriceLevelName,
                            'database' => $source,
                        ];
                    }
                }
            } catch (Throwable $e) {
                Log::warning('Retail product lookup failed', ['connection' => $connection, 'error' => $e->getMessage()]);
                $errors[$source] = 'Koneksi atau query database tidak berhasil.';
            }
        }

        $products = [];
        foreach ($results as $result) {
            $connection = $this->connectionForPath($result['path'] ?? '');
            $code = $this->itemCode($result['name']);
            $products[$result['id']] = $foundByConnection[$connection][$code] ?? null;
        }
        return compact('products', 'errors');
    }
}
