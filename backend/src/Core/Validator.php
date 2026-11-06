<?php
declare(strict_types=1);
namespace SportsHub\Core;
final class Validator {
    public static function id(mixed $value): int {
        if (!(is_int($value) || (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) || (float)$value > 2147483647 || (int)$value < 1) throw new ApiException('A positive integer identifier is required.', 400);
        return (int)$value;
    }
    public static function fields(array $body, array $spec, bool $partial = false): array {
        $errors = []; $clean = [];
        foreach ($body as $key => $unused) if (!array_key_exists($key, $spec)) $errors[$key] = ['This field is not editable.'];
        foreach ($spec as $key => $rule) {
            if (!array_key_exists($key, $body)) { if (!$partial) $errors[$key] = ['This field is required.']; continue; }
            $value = $body[$key]; $type = $rule[0]; $valid = true;
            if ($type === 'string' || $type === 'nullableString' || $type === 'rawString') {
                if ($type === 'nullableString' && $value === null) { $clean[$key] = null; continue; }
                $valid = is_string($value);
                if ($valid) { $value = $type === 'rawString' ? $value : trim($value); $length = mb_strlen($value, 'UTF-8'); $valid = mb_check_encoding($value, 'UTF-8') && $length >= ($rule[1] ?? 0) && $length <= ($rule[2] ?? 100); }
                $message = 'Use '.($rule[1] ?? 0).'–'.($rule[2] ?? 100).' characters.';
            } elseif ($type === 'int') { $valid = is_int($value) && $value >= $rule[1] && $value <= $rule[2]; $message = "Enter a whole number between {$rule[1]} and {$rule[2]}."; }
            elseif ($type === 'bool') { $valid = is_bool($value); $message = 'Use true or false.'; }
            elseif ($type === 'enum') { $valid = is_string($value) && in_array($value, $rule[1], true); $message = 'Choose '.implode(', ', $rule[1]).'.'; }
            elseif ($type === 'date') {
                $valid = is_string($value) && (bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})$/D', $value);
                if ($valid) {
                    try { $date = new \DateTimeImmutable($value); $details = \DateTimeImmutable::getLastErrors(); $valid = !$details || (!$details['warning_count'] && !$details['error_count']); if ($valid) { $date = $date->setTimezone(new \DateTimeZone('UTC')); $valid = (int)$date->format('Y') >= 1000 && (int)$date->format('Y') <= 9999; $value = $date->format('Y-m-d H:i:s.v'); } }
                    catch (\Exception) { $valid = false; }
                }
                $message = 'Use a valid ISO 8601 timestamp with timezone.';
            } else throw new \LogicException('Unknown validation rule.');
            if (!$valid) $errors[$key] = [$message]; else $clean[$key] = $value;
        }
        if ($partial && !$body) $errors['name'] = ['Supply at least one editable field.'];
        if ($errors) throw new ApiException('Please check the highlighted fields.', 422, $errors);
        return $clean;
    }
    public static function status(array $body, array $allowed): string { return self::fields($body, ['status' => ['enum', $allowed]])['status']; }
    public static function resource(string $resource, array $body): array {
        $text = ['string', 2, 100]; $date = ['date']; $id = ['int', 1, 2147483647]; $capacity = ['int', 1, 1000];
        $specs = [
            'clubs' => ['name'=>$text,'sport'=>['string',2,60],'description'=>['string',1,1000],'coach'=>$text,'location'=>$text,'capacity'=>$capacity],
            'schedules' => ['clubId'=>$id,'title'=>$text,'type'=>['enum',['training','match','event']],'startsAt'=>$date,'endsAt'=>$date,'location'=>$text],
            'tournaments' => ['name'=>$text,'sport'=>['string',2,60],'description'=>['string',1,1000],'location'=>$text,'startsAt'=>$date,'endsAt'=>$date,'registrationDeadline'=>$date,'capacity'=>$capacity],
            'matches' => ['tournamentId'=>$id,'homeTeam'=>$text,'awayTeam'=>$text,'startsAt'=>$date,'status'=>['enum',['scheduled','completed']],'homeScore'=>['int',0,999],'awayScore'=>['int',0,999]],
            'bookings' => ['facilityId'=>$id,'startsAt'=>$date,'endsAt'=>$date,'purpose'=>['string',3,300]],
        ];
        if (!isset($specs[$resource])) throw new \LogicException('Unknown resource.');
        $scheduled = $resource === 'matches' && ($body['status'] ?? '') === 'scheduled';
        if ($scheduled) {
            foreach (['homeScore','awayScore'] as $key) if (array_key_exists($key, $body) && $body[$key] !== null) throw new ApiException('Scheduled fixtures must have null scores.', 422, [$key=>['Use null for a scheduled fixture.']]);
            // Scheduled scores are nullable but must still be present in a full resource write.
            foreach (['homeScore','awayScore'] as $key) if (!array_key_exists($key, $body)) throw new ApiException('Scores must be explicitly null.',422,[$key=>['This field is required.']]);
            unset($specs[$resource]['homeScore'], $specs[$resource]['awayScore']);
            unset($body['homeScore'], $body['awayScore']);
        }
        $clean = self::fields($body, $specs[$resource]);
        if ($scheduled) { $clean['homeScore'] = null; $clean['awayScore'] = null; }
        $errors = [];
        if (isset($clean['endsAt']) && $clean['endsAt'] <= $clean['startsAt']) $errors['endsAt'] = ['End time must be after start time.'];
        if ($resource === 'tournaments' && $clean['registrationDeadline'] >= $clean['startsAt']) $errors['registrationDeadline'] = ['Registration must close before the tournament starts.'];
        if ($resource === 'matches' && mb_strtolower($clean['homeTeam']) === mb_strtolower($clean['awayTeam'])) $errors['awayTeam'] = ['Choose a different opponent.'];
        if ($resource === 'bookings') {
            if ($clean['startsAt'] <= gmdate('Y-m-d H:i:s.v')) $errors['startsAt'] = ['Choose a future start time.'];
            if ((float)(new \DateTimeImmutable($clean['endsAt'],new \DateTimeZone('UTC')))->format('U.u') - (float)(new \DateTimeImmutable($clean['startsAt'],new \DateTimeZone('UTC')))->format('U.u') > 14400) $errors['endsAt'] = ['Bookings may last at most four hours.'];
        }
        if ($errors) throw new ApiException('Please check the highlighted fields.',422,$errors);
        return $clean;
    }
}
