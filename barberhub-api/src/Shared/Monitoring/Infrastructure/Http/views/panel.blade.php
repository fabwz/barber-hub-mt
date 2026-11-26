<!doctype html>
<html lang="es-CR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('monitoring.title') }}</title>
    <style>
        :root { color-scheme: light; --ink: #1a1a1a; --muted: #4a4a4a; --line: #767676; --paper: #ffffff; --alert-bg: #fff4e5; --alert-ink: #7a2e00; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 400 1rem/1.5 system-ui, sans-serif; color: var(--ink); background: var(--paper); }
        main, header { max-width: 72rem; margin: 0 auto; padding: 1rem; }
        h1 { font-size: 1.5rem; font-weight: 500; margin: 0 0 .5rem; }
        h2 { font-size: 1.25rem; font-weight: 500; margin: 2rem 0 .5rem; border-bottom: 1px solid var(--line); padding-bottom: .25rem; }
        p { margin: .5rem 0; }
        .muted { color: var(--muted); }
        .scroll { overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; margin: .5rem 0 1rem; }
        caption { text-align: left; font-weight: 500; padding: .5rem 0; }
        th, td { border: 1px solid var(--line); padding: .5rem .75rem; text-align: left; vertical-align: top; }
        th { background: #f0f0f0; font-weight: 500; }
        td.num, th.num { text-align: right; }
        .alert { border: 1px solid var(--alert-ink); background: var(--alert-bg); color: var(--alert-ink); padding: .75rem 1rem; margin: .75rem 0; }
        .skip { position: absolute; left: -999px; }
        .skip:focus { left: 1rem; top: 1rem; background: var(--paper); padding: .5rem 1rem; border: 1px solid var(--ink); }
        a:focus-visible, :focus-visible { outline: 3px solid var(--ink); outline-offset: 2px; }
        .mono { font-family: ui-monospace, monospace; word-break: break-all; }
    </style>
</head>
<body>
<a class="skip" href="#contenido">{{ __('monitoring.skip_to_content') }}</a>
<header>
    <h1>{{ __('monitoring.title') }}</h1>
    <p class="muted">{{ __('monitoring.intro') }}</p>
    <p class="muted">{{ __('monitoring.window_hours', ['hours' => $policy->reportWindowHours]) }}</p>
</header>
<main id="contenido">

    <section aria-labelledby="errores">
        <h2 id="errores">{{ __('monitoring.errors_title') }}</h2>
        @if ($errorGroups === [])
            <p>{{ __('monitoring.errors_empty') }}</p>
        @else
            <div class="scroll">
                <table>
                    <caption>{{ __('monitoring.errors_caption') }}</caption>
                    <thead>
                    <tr>
                        <th scope="col">{{ __('monitoring.exception_class') }}</th>
                        <th scope="col">{{ __('monitoring.method') }}</th>
                        <th scope="col">{{ __('monitoring.route') }}</th>
                        <th scope="col">{{ __('monitoring.status_class') }}</th>
                        <th scope="col" class="num">{{ __('monitoring.requests') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($errorGroups as $group)
                        <tr>
                            <td class="mono">{{ $group->exceptionClass }}</td>
                            <td>{{ $group->method }}</td>
                            <td class="mono">{{ $group->route }}</td>
                            <td>{{ __('monitoring.status_class_value', ['class' => $group->statusClass]) }}</td>
                            <td class="num">{{ $group->requests }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section aria-labelledby="tiempos">
        <h2 id="tiempos">{{ __('monitoring.timings_title') }}</h2>
        @if ($timings === [])
            <p>{{ __('monitoring.timings_empty') }}</p>
        @else
            <div class="scroll">
                <table>
                    <caption>{{ __('monitoring.timings_caption') }}</caption>
                    <thead>
                    <tr>
                        <th scope="col">{{ __('monitoring.method') }}</th>
                        <th scope="col">{{ __('monitoring.route') }}</th>
                        <th scope="col" class="num">{{ __('monitoring.requests') }}</th>
                        <th scope="col" class="num">{{ __('monitoring.average_ms') }}</th>
                        <th scope="col" class="num">{{ __('monitoring.max_ms') }}</th>
                        <th scope="col" class="num">{{ __('monitoring.server_errors') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($timings as $timing)
                        <tr>
                            <td>{{ $timing->method }}</td>
                            <td class="mono">{{ $timing->route }}</td>
                            <td class="num">{{ $timing->requests }}</td>
                            <td class="num">{{ $timing->averageMs }}</td>
                            <td class="num">{{ $timing->maxMs }}</td>
                            <td class="num">{{ $timing->serverErrors }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section aria-labelledby="colas">
        <h2 id="colas">{{ __('monitoring.queues_title') }}</h2>
        @foreach ($queues as $queue)
            @if ($queue->late)
                <p class="alert" role="alert">{{ __('monitoring.queue_late') }}: {{ $queue->queue }} ({{ $queue->oldestSeconds }} s)</p>
            @endif
        @endforeach
        <div class="scroll">
            <table>
                <caption>{{ __('monitoring.queues_caption') }}</caption>
                <thead>
                <tr>
                    <th scope="col">{{ __('monitoring.queue') }}</th>
                    <th scope="col" class="num">{{ __('monitoring.pending') }}</th>
                    <th scope="col" class="num">{{ __('monitoring.delayed') }}</th>
                    <th scope="col" class="num">{{ __('monitoring.reserved') }}</th>
                    <th scope="col" class="num">{{ __('monitoring.oldest_seconds') }}</th>
                    <th scope="col">{{ __('monitoring.queue_state') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($queues as $queue)
                    <tr>
                        <th scope="row" class="mono">{{ $queue->queue }}</th>
                        <td class="num">{{ $queue->pending }}</td>
                        <td class="num">{{ $queue->delayed }}</td>
                        <td class="num">{{ $queue->reserved }}</td>
                        <td class="num">{{ $queue->oldestSeconds }}</td>
                        <td>{{ $queue->late ? __('monitoring.queue_late') : __('monitoring.queue_ok') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted">{{ __('monitoring.queues_note') }}</p>
    </section>

    <section aria-labelledby="fallidas">
        <h2 id="fallidas">{{ __('monitoring.failed_title') }}</h2>
        @if ($failed->groups === [])
            <p>{{ __('monitoring.failed_empty') }}</p>
        @else
            <div class="scroll">
                <table>
                    <caption>{{ __('monitoring.failed_groups_caption') }}</caption>
                    <thead>
                    <tr>
                        <th scope="col">{{ __('monitoring.job') }}</th>
                        <th scope="col">{{ __('monitoring.queue') }}</th>
                        <th scope="col" class="num">{{ __('monitoring.failures') }}</th>
                        <th scope="col">{{ __('monitoring.last_failure') }}</th>
                        <th scope="col">{{ __('monitoring.exception_class') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($failed->groups as $group)
                        <tr>
                            <td class="mono">{{ $group->job === '' ? __('monitoring.unknown_job') : $group->job }}</td>
                            <td class="mono">{{ $group->queue }}</td>
                            <td class="num">{{ $group->failures }}</td>
                            <td>{{ $format($group->lastFailedAt) }}</td>
                            <td class="mono">{{ $group->exceptionClass }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="scroll">
                <table>
                    <caption>{{ __('monitoring.failed_recent_caption') }}</caption>
                    <thead>
                    <tr>
                        <th scope="col">{{ __('monitoring.failed_at') }}</th>
                        <th scope="col">{{ __('monitoring.job') }}</th>
                        <th scope="col">{{ __('monitoring.queue') }}</th>
                        <th scope="col">{{ __('monitoring.exception_class') }}</th>
                        <th scope="col">{{ __('monitoring.identifier') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($failed->recent as $job)
                        <tr>
                            <td>{{ $format($job->failedAt) }}</td>
                            <td class="mono">{{ $job->job === '' ? __('monitoring.unknown_job') : $job->job }}</td>
                            <td class="mono">{{ $job->queue }}</td>
                            <td class="mono">{{ $job->exceptionClass }}</td>
                            <td class="mono">{{ $job->uuid }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section aria-labelledby="salud">
        <h2 id="salud">{{ __('monitoring.health_title') }}</h2>
        @if ($health->last === null)
            <p class="alert" role="alert">{{ __('monitoring.health_empty') }}</p>
        @else
            @if ($health->stale)
                <p class="alert" role="alert">{{ __('monitoring.health_stale', ['minutes' => $policy->staleAfterMinutes]) }}</p>
            @endif
            <p>
                <strong>{{ __('monitoring.health_last') }}:</strong>
                {{ $health->last->healthy() ? __('monitoring.health_ok') : __('monitoring.health_failed') }}
                ({{ $format($health->last->at) }})
            </p>
        @endif
        <p>
            <strong>{{ __('monitoring.availability', ['days' => $policy->availabilityWindowDays]) }}:</strong>
            @if ($health->availabilityPercent === null)
                {{ __('monitoring.availability_none') }}
            @else
                {{ __('monitoring.availability_value', ['percent' => number_format($health->availabilityPercent, 2, ',', ' '), 'total' => $health->totalChecks]) }}
            @endif
        </p>
        @if ($health->failures === [])
            <p>{{ __('monitoring.health_failures_empty') }}</p>
        @else
            <div class="scroll">
                <table>
                    <caption>{{ __('monitoring.health_failures_caption') }}</caption>
                    <thead>
                    <tr>
                        <th scope="col">{{ __('monitoring.last_check') }}</th>
                        <th scope="col">{{ __('monitoring.database') }}</th>
                        <th scope="col">{{ __('monitoring.queue_dependency') }}</th>
                        <th scope="col" class="num">{{ __('monitoring.checks') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($health->failures as $failure)
                        <tr>
                            <td>{{ $format($failure->at) }}</td>
                            <td>{{ $failure->databaseOk ? __('monitoring.dependency_ok') : __('monitoring.dependency_failed') }}</td>
                            <td>{{ $failure->queueOk ? __('monitoring.dependency_ok') : __('monitoring.dependency_failed') }}</td>
                            <td class="num">{{ $failure->checks }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

</main>
</body>
</html>
