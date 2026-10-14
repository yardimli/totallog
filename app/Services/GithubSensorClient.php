<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GithubSensorClient
{
    public function validateAccount(string $username, string $token): array
    {
        $response = $this->request($token)->get('/user');
        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response->status(), 'GitHub rejected this token.'));
        }

        $account = $response->json();
        if (! is_string($account['login'] ?? null) || strcasecmp($account['login'], $username) !== 0) {
            throw new RuntimeException('The token belongs to a different GitHub username.');
        }

        return $account;
    }

    public function commitsForDay(string $username, string $token, CarbonInterface $day): Collection
    {
        $items = collect();
        $page = 1;

        do {
            $response = $this->request($token)->get('/search/commits', [
                'q' => 'author:'.$username.' author-date:'.$day->toDateString(),
                'sort' => 'author-date',
                'order' => 'asc',
                'per_page' => 100,
                'page' => $page,
            ]);
            if (! $response->successful()) {
                throw new RuntimeException($this->errorMessage($response->status(), 'GitHub could not load commits.'));
            }

            $pageItems = collect($response->json('items', []));
            $items = $items->concat($pageItems);
            $total = min((int) $response->json('total_count', $items->count()), 1000);
            $page++;
        } while ($pageItems->count() === 100 && $items->count() < $total && $page <= 10);

        return $items->filter(fn ($item) => is_string(data_get($item, 'sha')) && is_string(data_get($item, 'repository.name')))
            ->unique('sha')
            ->map(function ($item) use ($day) {
                $timestamp = data_get($item, 'commit.author.date') ?: data_get($item, 'commit.committer.date');
                $localTime = $timestamp ? \Carbon\Carbon::parse($timestamp)->setTimezone(config('app.timezone'))->format('H:i:s') : '12:00:00';

                return [
                    'sha' => data_get($item, 'sha'),
                    'project' => data_get($item, 'repository.name'),
                    'repository' => data_get($item, 'repository.full_name'),
                    'url' => data_get($item, 'html_url'),
                    'message' => str(data_get($item, 'commit.message', ''))->before("\n")->trim()->limit(240)->value() ?: null,
                    'occurred_at' => $day->copy()->setTimeFromTimeString($localTime),
                ];
            })->sortBy('occurred_at')->values();
    }

    public function latestCommits(string $username, string $token): Collection
    {
        $response = $this->request($token)->get('/search/commits', [
            'q' => 'author:'.$username,
            'sort' => 'author-date',
            'order' => 'desc',
            'per_page' => 20,
            'page' => 1,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response->status(), 'GitHub could not load commits.'));
        }
        if ($response->json('incomplete_results') === true) {
            throw new RuntimeException('GitHub returned incomplete search results. Please try syncing again.');
        }

        return collect($response->json('items', []))->take(20)
            ->map(function (array $item) {
                $timestamp = data_get($item, 'commit.author.date') ?: data_get($item, 'commit.committer.date');
                if (! is_string(data_get($item, 'sha')) || ! is_string(data_get($item, 'repository.name')) || ! $timestamp) {
                    throw new RuntimeException('GitHub returned a commit without its identity or date. Please try syncing again.');
                }

                return [
                    'sha' => $item['sha'],
                    'project' => data_get($item, 'repository.name'),
                    'repository' => data_get($item, 'repository.full_name'),
                    'url' => data_get($item, 'html_url'),
                    'message' => str(data_get($item, 'commit.message', ''))->before("\n")->trim()->limit(240)->value() ?: null,
                    'occurred_at' => \Carbon\Carbon::parse($timestamp)->setTimezone(config('app.timezone')),
                ];
            })->unique('sha')->values();
    }

    private function request(string $token): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')
            ->withToken($token)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->withUserAgent(config('app.name', 'Total Log'))
            ->timeout(15);
    }

    private function errorMessage(int $status, string $fallback): string
    {
        return match ($status) {
            401 => 'GitHub rejected this token. Check that it is valid and has not expired.',
            403 => 'GitHub denied the request. Check the token permissions or API rate limit.',
            422 => 'GitHub could not search commits for this account.',
            default => $fallback,
        };
    }
}
