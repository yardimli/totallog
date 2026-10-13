export type ActivitySnapshot = {
  application: string;
  executable: string;
  window_title: string;
  process_id: number;
  idle_seconds: number;
  captured_at_unix_ms: number;
  supported: boolean;
};

function tracked(snapshot: ActivitySnapshot | null): snapshot is ActivitySnapshot {
  return Boolean(snapshot?.supported && snapshot.process_id && snapshot.idle_seconds < 180 && snapshot.application
    && snapshot.application.toLowerCase() !== 'total-log-desktop');
}

export function trackedSeconds(previous: ActivitySnapshot | null, current: ActivitySnapshot): number {
  if (!tracked(previous) || !tracked(current)) return 0;
  const elapsed = Math.round((current.captured_at_unix_ms - previous.captured_at_unix_ms) / 1000);
  // Never charge the gap across sleep, suspension, clock changes, or an idle transition.
  return elapsed > 0 && elapsed <= 10 ? elapsed : 0;
}
