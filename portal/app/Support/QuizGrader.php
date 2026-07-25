<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Classroom\QuizQuestion;
use Illuminate\Http\Request;

/**
 * Grades one student answer to one question. Objective types are scored
 * immediately; essays return is_correct=null / points=null for manual grading.
 *
 * Returns: [answer_text, selected_choice_ids(array|null), is_correct(bool|null), points_awarded(float|null)]
 */
class QuizGrader
{
    public static function grade(QuizQuestion $q, Request $request): array
    {
        $points = (float) $q->points;
        $meta = (array) ($q->meta ?? []);

        return match ($q->type) {
            'mcq', 'true_false' => self::single($q, $request, $points),
            'multi_select'      => self::multi($q, $request, $points),
            'identification', 'short_answer', 'fill_blank' => self::text($request, $q->id, $meta, $points),
            'matching'          => self::matching($request, $q->id, $meta, $points),
            'ordering'          => self::ordering($request, $q->id, $meta, $points),
            'essay'             => ['answer_text' => (string) $request->input("q.{$q->id}", ''), 'selected_choice_ids' => null, 'is_correct' => null, 'points_awarded' => null],
            default             => ['answer_text' => null, 'selected_choice_ids' => null, 'is_correct' => null, 'points_awarded' => null],
        };
    }

    private static function single(QuizQuestion $q, Request $request, float $points): array
    {
        $chosen = (int) $request->input("q.{$q->id}", 0);
        $correct = $q->choices->firstWhere('is_correct', true);
        $ok = $correct && (int) $correct->id === $chosen;
        return ['answer_text' => null, 'selected_choice_ids' => $chosen ? [$chosen] : [], 'is_correct' => $ok, 'points_awarded' => $ok ? $points : 0.0];
    }

    private static function multi(QuizQuestion $q, Request $request, float $points): array
    {
        $chosen = array_map('intval', (array) $request->input("q.{$q->id}", []));
        sort($chosen);
        $correct = $q->choices->where('is_correct', true)->pluck('id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $ok = $chosen === $correct && $correct !== [];
        return ['answer_text' => null, 'selected_choice_ids' => $chosen, 'is_correct' => $ok, 'points_awarded' => $ok ? $points : 0.0];
    }

    private static function text(Request $request, int $qid, array $meta, float $points): array
    {
        $answer = trim((string) $request->input("q.{$qid}", ''));
        $accepted = array_map([self::class, 'norm'], (array) ($meta['answers'] ?? []));
        $ok = $answer !== '' && in_array(self::norm($answer), $accepted, true);
        return ['answer_text' => $answer !== '' ? $answer : null, 'selected_choice_ids' => null, 'is_correct' => $ok, 'points_awarded' => $ok ? $points : 0.0];
    }

    private static function matching(Request $request, int $qid, array $meta, float $points): array
    {
        $pairs = (array) ($meta['pairs'] ?? []); // [['left'=>,'right'=>], ...]
        $answer = (array) $request->input("match.{$qid}", []); // [leftIndex => rightValue]
        $ok = $pairs !== [];
        foreach ($pairs as $i => $pair) {
            if (self::norm((string) ($answer[$i] ?? '')) !== self::norm((string) ($pair['right'] ?? ''))) { $ok = false; break; }
        }
        return ['answer_text' => json_encode($answer), 'selected_choice_ids' => null, 'is_correct' => $ok, 'points_awarded' => $ok ? $points : 0.0];
    }

    private static function ordering(Request $request, int $qid, array $meta, float $points): array
    {
        $items = (array) ($meta['items'] ?? []); // correct order
        $pos = (array) $request->input("order.{$qid}", []); // [itemIndex => position]
        // Reconstruct student's ordered sequence.
        asort($pos);
        $studentOrder = array_keys($pos); // itemIndexes in the order the student placed them
        $ok = $items !== [] && $studentOrder === range(0, count($items) - 1);
        return ['answer_text' => json_encode(array_values($pos)), 'selected_choice_ids' => null, 'is_correct' => $ok, 'points_awarded' => $ok ? $points : 0.0];
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $s) ?? ''));
    }
}
