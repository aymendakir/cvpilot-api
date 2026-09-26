<?php
namespace App\Services;

use Illuminate\Support\Str;

class JobLocation {
    public static function matches(array $job, array $search): bool {
        $country = strtoupper(trim((string) ($search['country'] ?? '')));
        if ($country === 'ALL') return !empty($job['remote']);
        if ($country === '') return true;
        $code = strtoupper(trim((string) ($job['country_code'] ?? '')));
        if ($code !== '') return $code === ($country === 'UK' ? 'GB' : $country);
        $location = strtolower(Str::ascii(trim((string) ($job['location'] ?? ''))));
        // Remote describes working style, not permission to work from every country.
        $worldwide = in_array($location, ['worldwide', 'anywhere', 'anywhere in the world', 'global', 'worldwide (remote)', 'remote (worldwide)'], true);
        if ($worldwide) return !empty($job['remote']) && ($search['work_mode'] ?? 'any') === 'remote';
        if ($country !== 'MA') return true;
        if (preg_match('/\b(morocco|maroc)\b/u', $location) || str_contains((string) ($job['location'] ?? ''), 'المغرب')) return true;
        // Country-less city/region labels are common on Moroccan job boards. Unknown
        // trailing regions are rejected (for example Rabat, Malta).
        $places = ['casablanca', 'rabat', 'tanger', 'tangier', 'tangiers', 'marrakech', 'marrakesh',
            'fes', 'fez', 'meknes', 'agadir', 'oujda', 'kenitra', 'sale', 'tetouan', 'safi', 'el jadida',
            'mohammedia', 'bouskoura', 'nouaceur', 'nouasseur', 'berrechid', 'settat', 'beni mellal',
            'nador', 'taza', 'larache', 'khouribga', 'ouarzazate', 'al hoceima', 'essaouira', 'temara',
            'casablanca-settat', 'rabat-sale-kenitra', 'tanger-tetouan-al hoceima', 'marrakech-safi',
            'marrakesh-safi', 'fes-meknes', 'souss-massa', 'oriental', 'beni mellal-khenifra'];
        $parts = array_filter(array_map('trim', explode(',', preg_replace('/ metropolitan area$/', '', $location))));
        return $parts !== [] && array_diff($parts, $places) === [];
    }
}
