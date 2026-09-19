<?php

namespace App\Http\Controllers;

use App\Jobs\ExportPdf;
use App\Models\Campaign;
use App\Models\Character;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BatchSheetController extends Controller
{
    /**
     * Show the batch sheet selection UI.
     * Accepts an optional campaign scoping parameter.
     */
    public function index(Request $request): Response
    {
        $campaignId = $request->query('campaign');

        $query = Character::with(['campaign'])
            ->orderBy('name');

        if ($campaignId) {
            $query->where('campaign_id', $campaignId);
        }

        $characters = $query->get([
            'id',
            'name',
            'race',
            'class',
            'subclass',
            'level',
            'class_path',
            'class_levels',
            'campaign_id',
            'player_name',
            'current_hp',
            'max_hp',
            'armor_class',
            'thac0',
            'experience_points',
        ]);

        $campaigns = Campaign::orderBy('name')->get(['id', 'name']);

        $selectedCampaign = $campaignId
            ? Campaign::find($campaignId, ['id', 'name'])
            : null;

        return Inertia::render('BatchSheets/Index', [
            'characters'       => $characters,
            'campaigns'        => $campaigns,
            'selectedCampaign' => $selectedCampaign,
        ]);
    }

    /**
     * Generate a combined PDF for the requested character IDs.
     * Returns a cache key to poll for completion status.
     */
    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'character_ids'   => ['required', 'array', 'min:1'],
            'character_ids.*' => ['required', 'integer', 'exists:characters,id'],
        ]);

        $characters = Character::with(['spells', 'inventoryItems', 'features', 'conditions', 'campaign'])
            ->whereIn('id', $validated['character_ids'])
            ->orderBy('name')
            ->get();

        $html = view('pdf.batch-sheets', [
            'characters' => $characters,
            'baseUrl'    => rtrim(url('/'), '/'),
        ])->render();

        $names    = $characters->pluck('name')->join('_');
        $filename = 'character-sheets-' . $this->slugify($names) . '.pdf';

        $key     = 'pdf_export_' . Str::uuid()->toString();
        $tmpHtml = tempnam(sys_get_temp_dir(), 'lorefire_batch_') . '.html';
        file_put_contents($tmpHtml, $html);

        Cache::put($key, ['status' => 'pending'], now()->addMinutes(10));

        ExportPdf::dispatch($key, $filename, $tmpHtml);

        return response()->json(['key' => $key]);
    }

    private function slugify(string $text): string
    {
        return substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), 0, 80);
    }
}
