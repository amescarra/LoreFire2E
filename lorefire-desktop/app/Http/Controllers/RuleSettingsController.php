<?php

namespace App\Http\Controllers;

use App\Models\Citation;
use App\Models\RuleCard;
use App\Models\RuleSource;
use App\Support\IngestLock;
use App\Support\RuleCards;
use App\Support\RuleSources;
use App\Support\TableLaw;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RuleSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Rules', [
            'sources' => RuleSources::rows(),
            'table_law' => TableLaw::current(),
            'cards' => RuleCards::all(),
            'citations' => Citation::query()->orderByDesc('id')->limit(50)->get([
                'id', 'work', 'year', 'pages', 'topic', 'source_code',
            ]),
            'fgg_opt_in' => IngestLock::fggOptedIn(),
            'fgg_allowed' => IngestLock::fggAllowed(),
            'ogl_row' => IngestLock::oglRowPresent(),
            'ingest_allowed' => IngestLock::allowedKinds(),
            'death_mode' => TableLaw::deathMode(),
        ]);
    }

    public function toggleSource(Request $request, string $code): RedirectResponse
    {
        $source = RuleSource::query()->where('code', $code)->firstOrFail();
        $enabled = $request->boolean('enabled', ! $source->enabled);
        $source->enabled = $enabled;
        $source->save();

        $label = $source->enabled ? 'enabled' : 'disabled';

        return back()->with('success', $source->code.' '.$label.'. Official prose is still not ingested.');
    }

    public function storeCard(Request $request): RedirectResponse
    {
        IngestLock::assertAllowed(IngestLock::KIND_USER_CARDS);
        IngestLock::assertSafeWrite($request->all());

        $data = $request->validate([
            'kind' => 'required|in:spell,monster,kit,racial_option',
            'name' => 'required|string|max:120',
            'source_code' => 'nullable|string|max:32',
            'effect_summary' => 'required|string|max:'.RuleCard::SUMMARY_MAX,
            'citation_work' => 'nullable|string|max:160',
            'citation_year' => 'nullable|integer|min:1970|max:1999',
            'citation_pages' => 'nullable|string|max:64',
            'citation_topic' => 'nullable|string|max:160',
            'user_verified' => 'sometimes|boolean',
        ]);

        $models = RuleCards::models();
        $class = $models[$data['kind']];
        unset($data['kind']);
        $data['user_verified'] = $request->boolean('user_verified');
        $class::query()->create($data);

        return back()->with('success', 'Card saved. Summary is yours, not book text.');
    }

    public function storeCitation(Request $request): RedirectResponse
    {
        IngestLock::assertAllowed(IngestLock::KIND_USER_CARDS);
        IngestLock::assertSafeWrite($request->all());

        $data = $request->validate([
            'work' => 'required|string|max:160',
            'year' => 'nullable|integer|min:1970|max:1999',
            'pages' => 'nullable|string|max:64',
            'topic' => 'required|string|max:160',
            'source_code' => 'nullable|string|max:32',
        ]);

        Citation::query()->create($data);

        return back()->with('success', 'Citation saved. No excerpt is stored.');
    }

    public function optInFgg(Request $request): RedirectResponse
    {
        IngestLock::assertSafeWrite($request->all());
        IngestLock::setFggOptIn($request->boolean('opt_in'));

        return back()->with(
            'success',
            $request->boolean('opt_in')
                ? 'FG&G opted in. Oracle may use the FG&G label. Official handbook prose is still not ingested.'
                : 'FG&G turned off.'
        );
    }
}
