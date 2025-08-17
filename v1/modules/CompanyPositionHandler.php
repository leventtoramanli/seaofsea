<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';

class PositionHandler
{
    public static function get_position_areas(array $p=[]): array { return self::areas($p); }
    public static function get_positions_by_area(array $p=[]): array { return self::byArea($p); }

    private static function areas(array $p): array
    {
        // UI map<String, List<String>> bekliyor
        return [
            'Ship'   => ['Deck', 'Engine', 'Catering'],
            'Office' => ['HR', 'Operations', 'IT', 'Finance'],
        ];
    }

    private static function byArea(array $p): array
    {
        $area = trim((string)($p['area'] ?? ''));
        $map = [
            'Deck'      => ['Captain','Chief Officer','Officer','Deckhand'],
            'Engine'    => ['Chief Engineer','2nd Engineer','Motorman'],
            'Catering'  => ['Cook','Steward'],
            'HR'        => ['HR Specialist','Recruiter'],
            'Operations'=> ['Coordinator','Supervisor'],
            'IT'        => ['SysAdmin','Developer'],
            'Finance'   => ['Accountant','Controller'],
        ];
        $list = $map[$area] ?? [];
        return array_map(fn($n)=>['name'=>$n], $list);
    }
}
