<?php

namespace App\Console\Commands;

use App\Models\DailyLog;
use App\Models\User;
use App\Services\HourlySensorRepair;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RepairHourlySensors extends Command
{
    protected $signature = 'sensors:repair-hourly
        {--user= : Required account ID or email}
        {--from= : First log date, YYYY-MM-DD (inclusive)}
        {--to= : Last log date, YYYY-MM-DD (inclusive)}
        {--discard-invalid-browsing : Remove browsing sessions with impossible hourly timestamps or durations}
        {--remove-browsing-domain=* : Remove ALL browsing activity for an exact domain in the selected dates}
        {--apply : Write changes; without this flag, preview only}';

    protected $description = 'Regroup browser, desktop, and mobile browser reports by hour, with a backup before writes';

    public function handle(HourlySensorRepair $repair): int
    {
        $input = ['user' => $this->option('user'), 'from' => $this->option('from'), 'to' => $this->option('to')];
        $validator = Validator::make($input, [
            'user' => 'required|string', 'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d'.($input['from'] ? '|after_or_equal:from' : ''),
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());
            return self::FAILURE;
        }
        $user = ctype_digit($input['user']) ? User::find($input['user']) : User::where('email', $input['user'])->first();
        if (! $user || $user->is_guest) {
            $this->error('Choose an existing non-demo account.');
            return self::FAILURE;
        }
        $domains = array_values(array_unique(array_map(fn ($domain) => strtolower(trim($domain, " \t\n\r\0\x0B.")), $this->option('remove-browsing-domain'))));
        foreach ($domains as $domain) {
            if (! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || ! str_contains($domain, '.')) {
                $this->error('Use exact domain names, without a scheme or path.');
                return self::FAILURE;
            }
        }
        if ($domains && (! $input['from'] || ! $input['to'])) {
            $this->error('Domain removal requires both --from and --to, to bound removal of real activity.');
            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $backup = null;
        $stream = null;
        try {
            if ($apply) {
                $directory = storage_path('app/sensor-repairs');
                if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    throw new \RuntimeException('Could not create the backup directory.');
                }
                $backup = $directory.'/'.now()->format('Ymd-His').'-'.$user->id.'-'.bin2hex(random_bytes(6)).'.jsonl';
                $stream = fopen($backup, 'x');
                if (! $stream) {
                    throw new \RuntimeException('Could not open the repair backup.');
                }
                chmod($backup, 0600);
                $this->info('Backup: '.$backup);
            }
            $this->info(($apply ? 'APPLY' : 'PREVIEW (no changes)').' — account '.$user->id.'; timezone '.config('app.timezone'));
            $totals = ['before' => 0, 'after' => 0, 'removed' => 0, 'duplicates' => 0, 'invalid' => 0];
            $logs = DailyLog::where('user_id', $user->id)
                ->when($input['from'], fn ($q, $date) => $q->whereDate('log_date', '>=', $date))
                ->when($input['to'], fn ($q, $date) => $q->whereDate('log_date', '<=', $date));
            $logs->chunkById(50, function ($logs) use ($repair, $user, $apply, $stream, $domains, &$totals) {
                foreach ($logs as $log) {
                    $summary = DB::transaction(function () use ($repair, $user, $apply, $stream, $domains, $log) {
                        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                        $plan = $repair->plan($log, (bool) $this->option('discard-invalid-browsing'), $domains);
                        if ($apply && ($plan['summary']['before'] || $plan['summary']['after'])) {
                            $line = json_encode($plan['backup'], JSON_THROW_ON_ERROR)."\n";
                            if (fwrite($stream, $line) !== strlen($line) || ! fflush($stream) || ! fsync($stream)) {
                                throw new \RuntimeException('Backup write failed; this day was not changed.');
                            }
                            $repair->apply($log, $plan);
                        }
                        return $plan['summary'];
                    });
                    foreach ($totals as $key => $value) {
                        $totals[$key] += $summary[$key];
                    }
                    if ($summary['before'] || $summary['after']) {
                        $this->line($log->log_date->toDateString().': '.json_encode($summary));
                    }
                }
            });
            $this->info('Totals: '.json_encode($totals));
            $this->line('Invalid sessions are only removed with --discard-invalid-browsing. Valid-looking historical AFK time cannot be inferred.');
            if (! $apply) {
                $this->line('Repeat with --apply to write these changes and save original records to a JSONL backup.');
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->line('The failing day was rolled back; earlier days may already be committed. Rerunning is supported.');
            return self::FAILURE;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
