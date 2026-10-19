/**
 * Container entry point: runs the websocket service (main.js) as a child process and restarts it
 * when it stops answering.
 *
 * Docker does not restart a container that is merely "unhealthy", and the service can wedge without
 * dying: when its event loop is busy it accepts no connections and handles no Redis messages, so no
 * booking completes. Nothing inside a busy event loop can notice that, and a node process that is
 * PID 1 in a container cannot be killed from inside, so the watchdog is a separate, tiny process.
 *
 * It probes the same URL as the image HEALTHCHECK, which only answers while the event loop is
 * serving. Limits: it sees a service that does not answer, not one that answers but has lost
 * Redis or the database; a restart drops open websocket connections and bookings in flight;
 * detection takes 30 to 60 s.
 *
 * WS_WATCHDOG=off runs the service directly, for hosts that supervise it themselves.
 */
import { spawn, ChildProcess } from 'child_process';
import * as http from 'http';
import * as path from 'path';

const PORT = parseInt(process.env.WS_PORT || '8080', 10);
const PROBE_PATH = '/socket.io/?EIO=4&transport=polling';
const START_GRACE_MS = 30_000;
const PROBE_INTERVAL_MS = 10_000;
const PROBE_TIMEOUT_MS = 4_000;
const MAX_PROBE_FAILURES = 3;
const STOP_GRACE_MS = 8_000;
const MIN_UPTIME_MS = 10_000;
const MAX_RESTART_DELAY_MS = 15_000;

function log(message: string) {
  console.log(`${new Date().toISOString()} [Watchdog] ${message}`);
}

/** True when the service answers the socket.io handshake in time. */
function probe(): Promise<boolean> {
  return new Promise((resolve) => {
    let settled = false;
    const finish = (ok: boolean) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      req.destroy();
      resolve(ok);
    };
    const req = http.get({ host: '127.0.0.1', port: PORT, path: PROBE_PATH }, (res) => {
      res.resume();
      finish(res.statusCode === 200);
    });
    const timer = setTimeout(() => finish(false), PROBE_TIMEOUT_MS);
    req.on('error', () => finish(false));
  });
}

function supervise() {
  let child: ChildProcess | null = null;
  let startedAt = 0;
  let failures = 0;
  let restartDelay = 1_000;
  let stopping = false;

  const start = () => {
    startedAt = Date.now();
    failures = 0;
    const current = spawn(process.execPath, [path.join(__dirname, 'main.js')], { stdio: 'inherit' });
    child = current;
    log(`Service started, pid ${current.pid}`);

    let handled = false;
    const onGone = (code: number | null, signal: NodeJS.Signals | null) => {
      if (handled) return;
      handled = true;
      const uptimeMs = Date.now() - startedAt;
      log(`Service stopped (code ${code}, signal ${signal}) after ${Math.round(uptimeMs / 1000)}s`);
      child = null;
      if (stopping) process.exit(0);
      // A service that dies at once is retried with growing pauses, not in a tight loop.
      restartDelay = uptimeMs < MIN_UPTIME_MS ? Math.min(restartDelay * 2, MAX_RESTART_DELAY_MS) : 1_000;
      log(`Restarting in ${restartDelay} ms`);
      setTimeout(start, restartDelay);
    };
    current.on('exit', onGone);
    current.on('error', (err) => {
      log(`Service could not be started: ${err.message}`);
      onGone(null, null);
    });
  };

  const watch = async () => {
    const current = child;
    if (!stopping && current && Date.now() - startedAt >= START_GRACE_MS) {
      const ok = await probe();
      if (child === current && !stopping) {
        if (ok) {
          failures = 0;
        } else if (++failures >= MAX_PROBE_FAILURES) {
          log(`No answer to ${failures} probes in a row, killing the service (pid ${current.pid})`);
          current.kill('SIGKILL');
          failures = 0;
        } else {
          log(`Probe failed (${failures}/${MAX_PROBE_FAILURES})`);
        }
      }
    }
    setTimeout(watch, PROBE_INTERVAL_MS);
  };

  const stop = (signal: NodeJS.Signals) => {
    if (stopping) return;
    stopping = true;
    log(`${signal} received, stopping the service`);
    if (!child) process.exit(0);
    child!.kill('SIGTERM');
    setTimeout(() => {
      log('Service did not stop in time, killing it');
      child?.kill('SIGKILL');
    }, STOP_GRACE_MS).unref();
  };
  process.on('SIGTERM', () => stop('SIGTERM'));
  process.on('SIGINT', () => stop('SIGINT'));

  start();
  setTimeout(watch, PROBE_INTERVAL_MS);
}

if ((process.env.WS_WATCHDOG || '').toLowerCase() === 'off') {
  require('./main');
} else {
  supervise();
}
