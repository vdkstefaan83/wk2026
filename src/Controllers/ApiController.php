<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\PredictionResolver;

final class ApiController extends Controller
{
    public function autosave(string $id): void
    {
        $user = $this->requireAuth();
        $form = Database::fetch('SELECT * FROM forms WHERE id = ? AND user_id = ?', [(int)$id, (int)$user['id']]);
        if (!$form) { $this->json(['ok' => false, 'error' => 'not found'], 404); return; }
        if ($form['status'] === 'submitted') { $this->json(['ok' => false, 'error' => 'locked'], 409); return; }

        $payload = $this->jsonInput();
        $token = $payload['_csrf'] ?? null;
        if (!\App\Core\Session::verifyCsrf(is_string($token) ? $token : null)) {
            $this->json(['ok' => false, 'error' => 'csrf'], 419); return;
        }

        Database::beginTransaction();
        try {
            foreach (($payload['scores'] ?? []) as $row) {
                $mid = (int)($row['match_id'] ?? 0);
                if ($mid <= 0) continue;
                $h = $row['home'] ?? null;
                $a = $row['away'] ?? null;
                $h = ($h === '' || $h === null) ? null : max(0, (int)$h);
                $a = ($a === '' || $a === null) ? null : max(0, (int)$a);
                $existing = Database::fetch(
                    'SELECT id FROM predictions WHERE form_id = ? AND match_id = ? AND stage = "group" AND slot_code = ""',
                    [(int)$form['id'], $mid]
                );
                if ($existing) {
                    Database::update('predictions', ['home_goals' => $h, 'away_goals' => $a], ['id' => $existing['id']]);
                } else {
                    Database::insert('predictions', [
                        'form_id'=>(int)$form['id'],'match_id'=>$mid,
                        'home_goals'=>$h,'away_goals'=>$a,'stage'=>'group','slot_code'=>'',
                    ]);
                }
            }
            foreach (($payload['slots'] ?? []) as $row) {
                $slot = trim((string)($row['slot'] ?? ''));
                if ($slot === '') continue;
                $teamId = isset($row['team_id']) && $row['team_id'] !== '' ? (int)$row['team_id'] : null;
                $stage = PredictionController::stageFromSlot($slot);
                $existing = Database::fetch(
                    'SELECT id FROM predictions WHERE form_id = ? AND slot_code = ?',
                    [(int)$form['id'], $slot]
                );
                if ($existing) {
                    Database::update('predictions', ['team_id' => $teamId, 'stage' => $stage], ['id' => $existing['id']]);
                } else {
                    Database::insert('predictions', [
                        'form_id'=>(int)$form['id'],'match_id'=>null,
                        'home_goals'=>null,'away_goals'=>null,
                        'stage'=>$stage,'slot_code'=>$slot,'team_id'=>$teamId,
                    ]);
                }
            }
            $patch = ['updated_at' => date('Y-m-d H:i:s')];
            if (array_key_exists('winner_team_id', $payload)) {
                $patch['winner_team_id'] = $payload['winner_team_id'] === '' || $payload['winner_team_id'] === null
                    ? null : (int)$payload['winner_team_id'];
            }
            if (array_key_exists('topscorer_player_id', $payload)) {
                $patch['topscorer_player_id'] = $payload['topscorer_player_id'] === '' || $payload['topscorer_player_id'] === null
                    ? null : (int)$payload['topscorer_player_id'];
            }
            if (array_key_exists('topscorer_custom_name', $payload)) {
                $val = trim((string)$payload['topscorer_custom_name']);
                $patch['topscorer_custom_name'] = $val === '' ? null : mb_substr($val, 0, 128);
            }
            if (array_key_exists('tiebreaker_value', $payload)) {
                $val = $payload['tiebreaker_value'];
                $patch['tiebreaker_value'] = ($val === '' || $val === null) ? null : max(0, (int)$val);
            }
            if (array_key_exists('label', $payload) && trim((string)$payload['label']) !== '') {
                $patch['label'] = trim((string)$payload['label']);
            }
            Database::update('forms', $patch, ['id' => $form['id']]);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500); return;
        }

        $this->state($id);
    }

    public function state(string $id): void
    {
        $user = $this->requireAuth();
        $form = Database::fetch('SELECT * FROM forms WHERE id = ? AND user_id = ?', [(int)$id, (int)$user['id']]);
        if (!$form) { $this->json(['ok' => false, 'error' => 'not found'], 404); return; }
        $resolved = PredictionResolver::resolve((int)$form['id']);
        $this->json([
            'ok'      => true,
            'form'    => [
                'id' => (int)$form['id'],
                'label' => $form['label'],
                'status' => $form['status'],
                'winner_team_id' => $form['winner_team_id'] ? (int)$form['winner_team_id'] : null,
                'topscorer_player_id' => $form['topscorer_player_id'] ? (int)$form['topscorer_player_id'] : null,
            ],
            'standings' => $resolved['standings'],
            'bracket'   => $resolved['bracket'],
            'downstream'=> $resolved['downstream'],
            'picks'     => $resolved['picks'],
        ]);
    }

    public function players(): void
    {
        $q = trim((string)$this->input('q', ''));
        $sql = 'SELECT p.id, p.name, t.name AS team_name, t.flag_emoji FROM players p
                  LEFT JOIN teams t ON t.id = p.team_id';
        $params = [];
        if ($q !== '') {
            $sql .= ' WHERE p.name LIKE ? OR t.name LIKE ?';
            $params = ['%'.$q.'%', '%'.$q.'%'];
        }
        $sql .= ' ORDER BY p.name LIMIT 50';
        $this->json(['players' => Database::fetchAll($sql, $params)]);
    }
}
