<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Core\Time;

/**
 * The site's timezone and clock, for apps, headless themes and scripts.
 *
 *   GET /api/v1/time                 timezone, offset, formats, now (UTC and local)
 *   GET /api/v1/timezones            every timezone: id, region, city, current offset
 *   GET /api/v1/time/convert         ?value=…&to=local|utc[&format=…]
 *
 * Public: none of it is private, and a front end needs it before sign-in.
 * Times in the API's other responses are UTC "Y-m-d H:i:s".
 */
class TimeController extends ApiController
{
    public function show(Request $request): Response
    {
        return Response::json(['data' => Time::info()]);
    }

    public function timezones(Request $request): Response
    {
        return Response::json(['data' => Time::all(), 'current' => Time::timezone()]);
    }

    /**
     * to=local: a stored UTC time (or any time with an offset) in the site
     *           timezone, as ISO 8601 plus the formatted text.
     * to=utc:   a time typed in the site timezone ("2026-10-10 13:32") as the
     *           UTC string to store, plus ISO 8601.
     */
    public function convert(Request $request): Response
    {
        $value = $request->query('value');
        $to = (string) ($request->query('to', 'local') ?? 'local');
        $format = $request->query('format');
        if (!is_string($value) || trim($value) === '' || strlen($value) > 64) {
            return Response::json(['error' => 'Give a time in ?value='], 422);
        }
        if ($format !== null && (!is_string($format) || strlen($format) > 40)) {
            return Response::json(['error' => 'format must be a PHP date() format string'], 422);
        }

        if ($to === 'utc') {
            $utc = Time::toUtc($value);
            if ($utc === '') return Response::json(['error' => 'Could not read that time.'], 422);
            return Response::json(['data' => [
                'input' => $value, 'timezone' => Time::timezone(),
                'utc' => $utc, 'iso' => Time::utc($utc . 'Z')?->format(\DateTimeInterface::ATOM),
            ]]);
        }
        if ($to !== 'local') return Response::json(['error' => 'to must be local or utc'], 422);

        $local = Time::local($value);
        if ($local === null) return Response::json(['error' => 'Could not read that time.'], 422);
        return Response::json(['data' => [
            'input' => $value, 'timezone' => Time::timezone(),
            'local' => $local->format(Time::STORAGE_FORMAT),
            'iso' => $local->format(\DateTimeInterface::ATOM),
            'formatted' => Time::format($local, is_string($format) ? $format : null),
            'ago' => Time::ago($local),
        ]]);
    }
}
