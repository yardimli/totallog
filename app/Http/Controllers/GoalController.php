<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\LogBlock;
use App\Models\TaskDefinition;
use App\Rules\CroppedIcon;
use App\Services\GoalProgressService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoalController extends Controller
{
    public function __construct(private GoalProgressService $progress) {}

    public function index(Request $request)
    {
        $goals = $request->user()->goals()->with(['sources.taskDefinition'])->latest()->get();
        $loggedProjects = LogBlock::query()->where('type', 'sensor_github')
            ->whereHas('dailyLog', fn ($query) => $query->where('user_id', $request->user()->id))
            ->get()->flatMap(fn (LogBlock $block) => collect(data_get($block->metadata, 'commits', []))->pluck('project'));
        $configuredProjects = $goals->flatMap(fn (Goal $goal) => $goal->sources->where('type', 'github')->pluck('github_project'));
        $projects = $loggedProjects->merge($configuredProjects)->filter()
            ->map(fn ($name) => $this->projectName($name))
            ->unique(fn ($name) => mb_strtolower($name))->sort()->values();

        return view('goals.index', [
            'goals' => $goals,
            'tasks' => TaskDefinition::where('user_id', $request->user()->id)->orderBy('position')->orderBy('id')->get(),
            'projects' => $projects,
        ]);
    }

    public function store(Request $request)
    {
        $goal = DB::transaction(function () use ($request) {
            $data = $this->validated($request);
            $goal = $request->user()->goals()->create($data['goal']);
            $this->replaceSources($goal, $data);

            return $goal;
        });
        $this->progress->sync($goal);

        return $request->expectsJson()
            ? response()->json(['message' => 'Goal created.', 'goal' => $goal, 'reload' => true], 201)
            : redirect()->route('goals.index')->with('status', 'Goal created.');
    }

    public function show(Request $request, Goal $goal)
    {
        $this->authorizeGoal($request, $goal);
        $this->progress->sync($goal);
        $date = rescue(fn () => Carbon::parse($request->query('date', now()))->startOfDay(), today(), false);
        $goal->load(['sources.taskDefinition']);

        return view('goals.show', [
            'goal' => $goal,
            'date' => $date,
            'snapshot' => $this->progress->snapshot($goal, $date, $request->user()->week_starts_on ?? 1),
            'history' => $this->progress->history($goal, $date, $request->user()->week_starts_on ?? 1),
        ]);
    }

    public function update(Request $request, Goal $goal)
    {
        $this->authorizeGoal($request, $goal);
        DB::transaction(function () use ($request, $goal) {
            $data = $this->validated($request);
            $goal->update($data['goal'] + ['completed_at' => null]);
            $goal->entries()->whereNotNull('external_key')->delete();
            $goal->sources()->delete();
            $this->replaceSources($goal, $data);
        });
        $this->progress->sync($goal->fresh());

        return $request->expectsJson()
            ? response()->json(['message' => 'Goal updated.', 'goal' => $goal->fresh(), 'reload' => true])
            : redirect()->route('goals.index')->with('status', 'Goal updated.');
    }

    public function destroy(Request $request, Goal $goal)
    {
        $this->authorizeGoal($request, $goal);
        $goal->delete();

        return redirect()->route('goals.index')->with('status', 'Goal deleted. Original logs and events were preserved.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100', 'emoji' => 'nullable|string|max:32',
            'icon_data' => ['nullable', 'string', 'max:500000', new CroppedIcon], 'remove_icon' => 'nullable|boolean',
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'target_points' => 'required|integer|min:1|max:1000000',
            'period' => 'required|in:daily,weekly,monthly,none',
            'start_date' => 'nullable|date', 'end_date' => 'nullable|date|after_or_equal:start_date',
            'task_definition_id' => 'nullable|integer', 'github_projects_text' => 'nullable|string|max:4000',
            'github_projects' => 'nullable|array|max:100',
            'github_projects.*' => 'string|max:255',
        ]);
        $taskId = filled($data['task_definition_id'] ?? null) ? (int) $data['task_definition_id'] : null;
        if ($taskId) {
            TaskDefinition::where('user_id', $request->user()->id)->findOrFail($taskId);
        }

        $goalData = [
            'name' => $data['name'], 'emoji' => ($data['emoji'] ?? null) ?: Goal::DEFAULT_EMOJI,
            'color' => strtolower($data['color']), 'target_points' => $data['target_points'], 'period' => $data['period'],
            'start_date' => $data['start_date'] ?? null, 'end_date' => $data['end_date'] ?? null,
            'manual_enabled' => $request->boolean('manual_enabled'),
        ];
        if (filled($data['icon_data'] ?? null)) {
            $goalData['icon_data'] = $data['icon_data'];
        } elseif ($request->boolean('remove_icon')) {
            $goalData['icon_data'] = null;
        }

        return [
            'goal' => $goalData,
            'task_id' => $taskId,
            'projects' => collect($data['github_projects'] ?? [])
                ->merge(preg_split('/[\r\n,]+/', $data['github_projects_text'] ?? ''))
                ->map(fn ($name) => $this->projectName($name))->filter()->unique(fn ($name) => mb_strtolower($name))->values(),
        ];
    }

    private function replaceSources(Goal $goal, array $data): void
    {
        if ($data['task_id']) {
            $goal->sources()->create(['type' => 'event', 'task_definition_id' => $data['task_id']]);
        }
        $data['projects']->each(fn ($project) => $goal->sources()->create(['type' => 'github', 'github_project' => $project]));
    }

    private function authorizeGoal(Request $request, Goal $goal): void
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
    }

    private function projectName(?string $name): string
    {
        return trim((string) collect(explode('/', trim((string) $name, '/')))->last());
    }
}
