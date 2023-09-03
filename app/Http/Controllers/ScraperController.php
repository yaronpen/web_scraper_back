<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\Crawler;
use App\Models\Scrape;
use App\Exceptions\ScraperHandler;

class ScraperController extends Controller
{
    public function index(Request $req)
    {
        $req->validate([
            'url' => 'required',
            'depth' => 'required'
        ]);

        $url = $req->url;
        $depth = $req->depth;

        $this->validateUrl($url);

        $response = $this->store($url, $depth);

        return $response;
    }

    private function store($url, $depth)
    {
        $scrape = Scrape::where('url', $url)->where('depth', $depth)->count();
        $stored_flag = true;
        
        if (is_null($scrape) === true || $scrape === 0) {

            $scraped_data = $this->scrapeUrl($url, $depth);
            $stored_scrape = new Scrape();
            $stored_scrape->url = $url;
            $stored_scrape->scraped_data = $scraped_data;
            $stored_scrape->depth = $depth;
            $stored_scrape->save();
        } else {
            $scrape = Scrape::where('url', $url)->where('depth', $depth)->get();

            $scraped_data = $scrape[0]->scraped_data;
        }

        return ['data' => ['url' => $url, 'depth' => $depth, 'scraped_data' => $scraped_data], 'stored' => $stored_flag];
    }

    public function update(Request $req)
    {
        $req->validate([
            'url' => 'required',
            'depth' => 'required'
        ]);

        $url = $req->url;
        $depth = $req->depth;
        $this->validateUrl($url);

        $scraped_data = $this->scrapeUrl($url, $depth);
        Scrape::where('url', $url)->where('depth', $depth)->update(['scraped_data' => $scraped_data]);

        return ['data' => ['url' => $url, 'depth' => $depth, 'scraped_data' => $scraped_data]];
    }

    private function scrapeUrl($url, $depth)
    {
        set_time_limit(0);
        
        $client = new HttpBrowser();
        $temp_arr = [$url];
        $sub_urls = [];
        $final_results = [];
        $previous_results = [];

        for ($i = 1; $i <= $depth; $i++) {
            if ($i > 1) {
                $temp_arr = $sub_urls;
            }

            foreach ($temp_arr as $current_url) {
                $http_response = $client->request('GET', $current_url);
                $parsed_url = parse_url($current_url);
                $website = $http_response->html();
                $dom_crawler = new Crawler($website);

                $host = isset($parsed_url['host']) === true ? $parsed_url['host'] : $parsed_url['path'];
                $previous_results = $sub_urls;
                $sub_urls = $dom_crawler->filter('a')->each(function (Crawler $node) use ($host) {
                    $temp_single_url = $node->attr('href');

                    return $this->parseURL($temp_single_url, $host);
                });
            }
            
            $sub_urls = array_filter($sub_urls);
            // preventing duplicates in current results
            $sub_urls = array_unique($sub_urls);
            // preventing duplicates within preivous and current results
            $sub_urls = array_diff($sub_urls, $previous_results);
            array_push($final_results, ['depth' => $i, 'data' => $sub_urls]);
        }

        return $final_results;
    }

    private function parseURL($temp_single_url, $host)
    {
        $the_url = '';
        if (substr($temp_single_url, 0, 1) !== '#' && $temp_single_url !== '/') {
            $parsed_single_url = parse_url($temp_single_url);
            $alternative_url = $host . $temp_single_url;
            if (
                !empty($parsed_single_url['host']) === true ||
                substr(strval($temp_single_url), 0, 3) == 'www' ||
                substr(strval($temp_single_url), 0, 4) == 'http'
            ) {
                $the_url = $temp_single_url;
            } else {
                $the_url = "http://$alternative_url";
            }
            $check_alive = $this->AliveURL($the_url);

            if ($check_alive === true) {
                return $the_url;
            }
        }
    }

    private function AliveURL($url)
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
        $retcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $retcode >= 400 && $retcode != 999 ? false : true;
    }

    protected function validateUrl($url)
    {
        $pattern = '#((https?)://(\S*?\.\S*?))([\s)\[\]{},;"\':<]|\.\s|$)#i';

        if (!preg_match($pattern, $url) === true) {
            throw ScraperHandler::validationUrlLHandler();
        }
    }
}
