<?php

namespace App\Http\Controllers;

use Excel;
use DateTime;
use \App\Models\Group;
use \App\Models\Topic;
use \App\Models\Keyword;
use \App\Models\Setting;
use Illuminate\Http\Request;
use \App\Models\SearchResult;
use \App\Imports\KeywordImport;
use App\Services\SerpApiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use App\Services\SerpApiServiceScheduler;

class KeyWordClusterController extends Controller
{
    private SerpApiService $serpApiService;
    private SerpApiServiceScheduler $SerpApiServiceScheduler;
    private Keyword $keyword;
    private SearchResult $searchResult;
    private Group $group;
    private Topic $topic;

    public function __construct(SerpApiServiceScheduler $SerpApiServiceScheduler, SerpApiService $serpApiService, keyword $keyword, SearchResult $searchResult, Group $group, Topic $topic)
    {
        $this->serpApiService = $serpApiService;
        $this->SerpApiServiceScheduler = $SerpApiServiceScheduler;
        $this->keyword = $keyword;
        $this->searchResult = $searchResult;
        $this->group = $group;
        $this->topic = $topic;
    }
    public function index(Request $request)
    {
        try {
            set_time_limit(0);
            return view('welcome');
        } catch (\Exception $ex) {
            dd($ex);
        }
    }

    public function processKeywordXlsx(Request $request)
    {
        set_time_limit(0);
        $keywords = null;
        $success_message = "";
        $total_inserted_count = 0;
        $request->validate([
            'file' => 'required|mimes:xlsx'
        ]);
        if ($request->file()) {
            $keywords = Excel::toArray(new KeywordImport, $request->file('file'));
            $non_existing_keywords = $this->keyword->getNonexistentKeywords($keywords);
            if (count($non_existing_keywords) > 0) {
                $non_existing_keywords_with_volume = getKeywordsWithVolume($keywords, $non_existing_keywords);
                /**
                 *This code is only used for to get get count of keyword
                 */
                $get_count_of_already_exist_keyword = count(array_diff(getKeyWordValueFromImportedData($keywords), $non_existing_keywords));

                $keyword_data = modifyKeywordsAccordingSerp($non_existing_keywords);
                if (count($keyword_data) > 0) {
                    $keyword_data = array_chunk($keyword_data, 2);
                    foreach ($keyword_data as $k_words) {
                        $api_response = $this->SerpApiServiceScheduler->addKeywordsToSerpScheduler($k_words);
                        if (isset($api_response['success']) and (count($api_response['success']) > 0)) {
                            $non_existing_keywords_with_volume_data = getKeywordsDataWithVolume($non_existing_keywords_with_volume, $api_response['success']);
                            $result = $this->keyword->store($non_existing_keywords_with_volume_data);
                            if ($result) {
                                $total_inserted_count = $total_inserted_count + count($non_existing_keywords_with_volume_data);
                            }
                        }
                    }
                }

                if ($get_count_of_already_exist_keyword > 0) {
                    $success_message = $get_count_of_already_exist_keyword . ' Keywords are already exist and ';
                }
                $success_message .= $total_inserted_count . " New Records saved Successfully";
                Session::flash("info", $success_message);
            } else {
                Session::flash("warning", " All Keyword are already exsist");
            }

            return redirect()->route('home');
        }
    }

    public function getListing(Request $request)
    {
        $setting = Setting::first();
        $data = \App\Models\Topic::with(['keywords.searchResults'])->get()->sortByDesc(function ($group) {
            return count($group->keywords);
        })->map(function ($group) {
            $total_positions = 0;
            $total_volume = 0;
            $count = 0;
            foreach ($group->keywords as $keyword) {
                $count_specific_keyword = 0;
                $total_specific_keyword = 0;
                foreach ($keyword->searchResults as $search_result) {
                    if ($search_result->position) {
                        $total_positions += $search_result->position;
                        $total_specific_keyword += $search_result->position;
                        $count++;
                        $count_specific_keyword++;
                    }
                }
                $averagePositionForKeyword = $count_specific_keyword > 0 ? round($total_specific_keyword / $count_specific_keyword, 2) : 0;
                $keyword->average_position = $averagePositionForKeyword;
                $total_volume = $total_volume + $keyword->volume;
            }
            $averagePosition = $count > 0 ? round($total_positions / $count, 2) : 0;
            $group->average_position = $averagePosition;
            $group->total_volume = $total_volume;
            return $group;
        });
        if (is_object($data) && count($data) > 0) {
            Session::flash("success", count($data)." Topics Fetched Successfully");
        } else {
            Session::flash("success", " You don't have any records");
        }

        // printArray($data->toArray());
        return view('records')->with('records', $data);
    }

    public function settings()
    {
        $setting = Setting::first();
        // dd($setting->toArray());
        return view('settings.index', compact('setting'));
    }

    public function updateSettings(Request $request)
    {

        $this->validate($request, [
            'name' => 'required',
            'value' => 'required|numeric|gt:0',
        ]);
        if ($request->id) {
            $setting = Setting::find($request->id);
        } else {
            $setting = new Setting;
        }

        $setting->name = $request->name;
        $setting->value = $request->value;

        $result = $setting->save();
        if ($result) {
            return redirect()->route('settings')->with('success', 'Settings Updated successfully');
        } else {
            return redirect()->route('settings')->with('error', 'Something went wrong, Please try again');
        }
    }

    public function runScheduler(Request $request)
    {
        $keywords = [];

        if ($request->isMethod('POST')) {

            $from_time = strtotime(Date('Y-m-d H:i:s'));
            set_time_limit(0);
            $serpApiService = new SerpApiService();
            $keyword = new keyword();
            $searchResult = new SearchResult;
            $keyword_data = Keyword::select('name as keyword', 'volume', 'serp_user_id', 'serp_keyword_id')->whereNull('topic_id')->limit(100)->get()->toArray();

            if (count($keyword_data) > 0) {

                $keywords = array_map(function ($item) {
                    return $item["keyword"];
                }, $keyword_data);

                $serp_keyword_id = array_map(function ($item) {
                    return $item["serp_keyword_id"];
                }, $keyword_data);
                $search_records = $this->SerpApiServiceScheduler->fetchSerpApiDataResult($keyword_data);

                if ((count($search_records['for_group']) > 0) and (count($search_records['for_database']) > 0)) {
                    foreach ($search_records['for_group'] as $keywords_group) {
                        if (count($keywords_group['keywords']) > 0) {
                            $keyword->createKeywordGroup($keywords_group['keywords']);
                        }
                    }
                    $searchResult->store($search_records['for_database']);

                    $final_keyword = getOnlyKeywordsFromGroup($search_records['for_group']);
                    // printArray($keywords,$final_keyword);

                    Topic::deleteGroupWithoutRelationship();
                    Session::flash('success', count($final_keyword) . ' New records save successfully');

                    if(isset($search_records['error'])){
                        Session::flash('danger', $search_records['error']);
                    }

                    $to_time = strtotime(Date('Y-m-d H:i:s'));

                    $diff_minutes = round(abs($from_time - $to_time) / 60, 2) . " minutes";
                    Session::flash('info', 'Total time taken by scheduler : ' . $diff_minutes);
                    Session::flash('keywords', $final_keyword);
                    return redirect()->route('run.scheduler');
                } elseif (isset($search_records['error'])) {
                    Session::flash("danger", $search_records['error']);
                    return redirect()->route('run.scheduler');
                } else {
                    Session::flash("danger", "Somthing went wrong, Please try again");
                    return redirect()->route('run.scheduler');
                }
            } else {
                $keywords = [];
                return redirect()->route('run.scheduler')->with("danger", "You have not any keywords to search for. Please upload new keyword and then try again");
            }
        } else {
            $keywords = [];
            return view('run_scheduler', compact('keywords'));
        }
    }

    public function testScheduler(Request $request)
    {
        $keywords = array('cofee', 'dog', 'elephant', 'moneky');
        $data = $this->SerpApiServiceScheduler->fetchSerpApiData($keywords);

        dd($data);
    }

    public function reArrangeKeywords(Request $request){
        if($request->isMethod('POST')){
            $from_time = strtotime(Date('Y-m-d H:i:s'));
            set_time_limit(0);
            $serpApiService = new SerpApiService();
            $keyword = new keyword();
            $searchResult = new SearchResult;
            $keyword_data = Keyword::select('id','name as keyword', 'volume', 'serp_user_id', 'serp_keyword_id')->with('searchResults')->orderBy('name', 'ASC')->get()->toArray();
            $topic = new Topic;
            if(count($keyword_data) > 0){
                $search_records = $this->SerpApiServiceScheduler->reArrangeResults($keyword_data);

                $keywords = array_map(function ($item) {
                    return $item["keyword"];
                }, $keyword_data);

                if ((count($search_records['for_group']) > 0) and (count($search_records['for_database']) > 0)) {
                    Topic::truncateData();
                    foreach ($search_records['for_group'] as $keywords_group) {
                        if (count($keywords_group['keywords']) > 0) {
                            $keyword->createKeywordGroup($keywords_group['keywords']);
                        }
                    }
                    // $searchResult->store($search_records['for_database']);

                    Topic::deleteGroupWithoutRelationship();
                    Session::flash('success', count($keywords) . ' keywords are  rearrange');

                    $to_time = strtotime(Date('Y-m-d H:i:s'));

                    $diff_minutes = round(abs($from_time - $to_time) / 60, 2);
                    $diff_second = $from_time - $to_time;
                    Session::flash('info', 'Total Re-arrange time : ' . $diff_minutes . ' minutes');
                    Session::flash('keywords', $keywords);
                    return redirect()->route('re.arrange.keyword');
                } elseif (isset($search_records['error'])) {
                    Session::flash("danger", $search_records['error']);
                    return redirect()->route('re.arrange.keyword');
                } else {
                    Session::flash("danger", "Somthing went wrong, Please try again");
                    return redirect()->route('re.arrange.keyword');
                }
            }
            else{
                Session::flash("danger", "You have not any keywords to search for. Please upload new keyword and then try again");
                return redirect()->route('re.arrange.keyword');
            }
        }
        else{
            $keywords = [];
            return view('re_arrange_keywords', compact('keywords'));
        }
    }

    public function webhooks(Request $request){
        try {

            $secret_sign_key = 'Keam1oFqG1I9UT7oX7IgUElLN1bvyBU';
            $webhook_content = file_get_contents('php://input'); //raw webhook request body
            $received_signature = $_SERVER["HTTP_X_SERPHOUSE_SIGNATURE"];
            $expected_signature = hash_hmac('sha256', $webhook_content, $secret_sign_key);

            if($expected_signature != $received_signature) {
                Log::info('Webhook received Signature not matched:', ['payload' => $request->all()]);
            } else {
                Log::info('Webhook received signature matched:', ['payload' => $request->all()]);
            }
        }
        catch(\Exception $e){
            Log::info('Error:', ['payload' => $request->all()]);
        }
    }
}
