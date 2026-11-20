<?php

namespace App\Domain\Leaderboard;

enum LeaderboardScope: string
{
    case All = 'all';
    case Season = 'season';
    case Weekly = 'weekly';
}
