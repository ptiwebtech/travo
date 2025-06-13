<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CustomLookupController extends Controller
{
    public function fleetbaseBlog(Request $request)
    {
        $limit = $request->integer('limit', 6);
        $rssUrl = 'https://services.travo.ng/feed';
        $posts = [];

        libxml_use_internal_errors(true);

        try {
            $rss = simplexml_load_file($rssUrl, 'SimpleXMLElement', LIBXML_NOCDATA);

            if (!$rss || !isset($rss->channel->item)) {
                return response()->json(['error' => 'Failed to load RSS feed.'], 500);
            }

            foreach ($rss->channel->item as $item) {
                $posts[] = [
                    'title'           => trim((string) $item->title),
                    'link'            => trim((string) $item->link),
                    'guid'            => trim((string) $item->guid),
                    'description'     => trim((string) $item->description),
                    'pubDate'         => trim((string) $item->pubDate),
                    'media_content'   => trim((string) data_get($item, 'media:content.url')),
                    'media_thumbnail' => trim((string) data_get($item, 'media:thumbnail.url')),
                ];
            }

            $posts = array_slice($posts, 0, $limit);

            return response()->json($posts);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while fetching blog posts.'], 500);
        }
    }

}
