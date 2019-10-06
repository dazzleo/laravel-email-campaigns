<?php

namespace Spatie\EmailCampaigns\Actions;

use DOMElement;
use DOMDocument;
use ErrorException;
use Illuminate\Support\Str;
use Spatie\EmailCampaigns\Models\Campaign;
use Spatie\EmailCampaigns\Exceptions\CampaignCouldNotBeSent;
use Spatie\EmailCampaigns\Http\Controllers\TrackOpensController;
use Spatie\EmailCampaigns\Http\Controllers\CampaignWebviewController;

class PrepareEmailHtmlAction
{
    public function execute(Campaign $campaign)
    {
        $campaign->email_html = $campaign->html;

        if ($campaign->track_clicks) {
            $this->trackClicks($campaign);
        }

        if ($campaign->track_opens) {
            $this->trackOpens($campaign);
        }

        $this->replacePlaceholders($campaign);

        $campaign->save();
    }

    protected function trackClicks(Campaign $campaign)
    {
        $dom = new DOMDocument('1.0', 'UTF-8');

        try {
            $dom->loadHTML($campaign->email_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING);
        } catch (ErrorException $errorException) {
            throw CampaignCouldNotBeSent::invalidContent($campaign, $errorException);
        }

        collect($dom->getElementsByTagName('a'))
            ->filter(function (DOMElement $linkElement) {
                return Str::startsWith(
                    $linkElement->getAttribute('href'),
                    ['http://', 'https://']
                );
            })
            ->each(function (DOMElement $linkElement) use ($campaign) {
                $originalHref = $linkElement->getAttribute('href');

                $campaignLink = $campaign->links()->create([
                    'original_link' => $originalHref,
                ]);

                $linkElement->setAttribute('href', $campaignLink->url);
            });

        $campaign->email_html = $dom->saveHtml();
    }

    protected function trackOpens(Campaign $campaign)
    {
        $webBeaconUrl = action(TrackOpensController::class, '@@campaignSendUuid@@');

        $webBeaconHtml = "<img alt='beacon' src='{$webBeaconUrl}' />";

        $campaign->email_html = Str::replaceLast('</body>', $webBeaconHtml.'</body>', $campaign->email_html);
    }

    protected function replacePlaceholders(Campaign $campaign): void
    {
        $webviewUrl = $campaign->webViewUrl();

        $campaign->email_html = str_replace('@@webviewUrl@@', $webviewUrl, $campaign->email_html);
    }
}
