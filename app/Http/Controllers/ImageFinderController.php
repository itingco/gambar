<?php
namespace App\Http\Controllers;

use App\Services\DriveClient;
use App\Services\DriveImageIndex;
use App\Services\ImageMatcher;
use App\Services\RetailProductLookup;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ImageFinderController extends Controller
{
    public function index(DriveImageIndex $index) { return view('finder', ['count'=>count($index->load())]); }

    public function search(Request $request, ImageMatcher $matcher, DriveImageIndex $index, RetailProductLookup $products)
    {
        $request->validate(['image'=>'required|image|mimes:jpeg,png,webp,gif|max:10240', 'limit'=>'nullable|integer|min:1|max:40']);
        $features = $matcher->features($request->file('image')->getRealPath());
        if (!$features) return response()->json(['message'=>'Gambar tidak dapat dibaca'], 422);
        $all = $index->load(); $results = [];
        foreach ($all as $id=>$item) {
            $results[] = ['id'=>$id,'name'=>$item['name'],'path'=>$item['path'],
                'score'=>$matcher->score($features, $item['features']),
                'url'=>route('finder.image', ['id'=>$id]),
                'drive_url'=>'https://drive.google.com/file/d/'.rawurlencode($id).'/view'];
        }
        usort($results, static fn ($a,$b) => $b['score'] <=> $a['score']);
        $aboveThreshold = array_values(array_filter($results, static fn ($row) => $row['score'] > 90));
        $chosen = count($aboveThreshold) ? $aboveThreshold : array_slice($results, 0, 1);
        $lookup = $products->findForResults($chosen);
        foreach ($chosen as &$row) {
            $code = $products->itemCode($row['name']);
            $row['item_code'] = $code;
            $row['product'] = $lookup['products'][$row['id']] ?? null;
            unset($row['drive_url']);
        }
        unset($row);
        return response()->json([
            'database_errors' => $lookup['errors'],
            'total' => count($all),
            'threshold' => 90,
            'fallback' => count($aboveThreshold) === 0 && count($results) > 0,
            'results' => $chosen,
        ]);
    }

    public function image(string $id, DriveImageIndex $index, DriveClient $drive)
    {
        abort_unless(isset($index->load()[$id]), 404);
        try {
            $tmp = $drive->fetchToTemporaryFile($id);
            $info = @getimagesize($tmp);
            if (!$info || !in_array($info['mime']??'', ['image/jpeg','image/png','image/webp','image/gif'], true)) {
                @unlink($tmp); abort(415, 'Format gambar tidak didukung');
            }
            $bytes = file_get_contents($tmp); @unlink($tmp);
            return response($bytes, 200, ['Content-Type'=>$info['mime'], 'Cache-Control'=>'private, max-age=300', 'X-Content-Type-Options'=>'nosniff']);
        } catch (\Throwable $e) {
            report($e); abort(502, 'Gagal mengambil gambar dari Drive');
        }
    }
}
