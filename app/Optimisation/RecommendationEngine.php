<?php
declare(strict_types=1);

namespace BMT\Optimisation;

final class RecommendationEngine
{
    public function fromEfficiency(array $campaigns): array
    {
        $out = [];
        foreach ($campaigns as $c) {
            if ((int)$c['clicks'] >= 25 && (float)$c['conversions'] <= 0.0 && (float)$c['cost'] > 0) {
                $out[] = ['risk'=>'medium','action'=>'review_search_terms','campaign'=>$c['campaign_name'],'reason'=>'25+ clicks with spend but no recorded conversions'];
            }
            if ((float)$c['conversions'] > 0 && $c['cpa'] !== null) {
                $out[] = ['risk'=>'low','action'=>'review_winning_ad_content','campaign'=>$c['campaign_name'],'reason'=>'Campaign has recorded conversions; review search intent and ad/landing-page patterns for scalable content'];
            }
        }
        return $out;
    }
}
