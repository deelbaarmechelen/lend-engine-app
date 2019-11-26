<?php

namespace AppBundle\Services;

use Postmark\PostmarkClient;

class EmailService
{
    /** @var TenantService  */
    private $tenantService;

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    /**
     * Intentionally soaks up the exceptions silently
     *
     * @param $toEmail
     * @param $toName
     * @param $subject
     * @param $message
     * @param bool $ccAdmin
     * @return bool
     */
    public function send($toEmail, $toName, $subject, $message, $ccAdmin = false)
    {

        if (!$toEmail) {
            return false;
        }

        $senderName     = $this->tenantService->getCompanyName();
        $replyToEmail   = $this->tenantService->getReplyToEmail();
        $fromEmail      = $this->tenantService->getSenderEmail();
        $postmarkApiKey = $this->tenantService->getSetting('postmark_api_key');

        $client = new PostmarkClient($postmarkApiKey);

        try {
            $client->sendEmail(
                "{$senderName} <{$fromEmail}>",
                $toEmail,
                $subject,
                $message,
                null,
                null,
                true,
                $replyToEmail
            );
        } catch (\Exception $e) {

        }

        if ($ccAdmin == true) {
            // Insert a green box at the top of the content
            $message = preg_replace('/<!--\/\/-->/', $this->addAdminInfo($toName, $toEmail), $message);

            try {
                $client->sendEmail(
                    "{$senderName} <{$fromEmail}>",
                    $replyToEmail,
                    '[Lend Engine CC] '.$subject,
                    $message,
                    null,
                    null,
                    true,
                    $replyToEmail
                );
            } catch (\Exception $e) {

            }
        }

        return true;

    }

    /**
     * @param $toName
     * @param $toEmail
     * @return string
     */
    private function addAdminInfo($toName, $toEmail)
    {
        $msg = "This is a copy of the email sent to {$toName} ({$toEmail}).";
        return '<div style="padding: 10px; background-color: #d5f996; border-radius: 4px; margin-bottom: 10px;">'.$msg.'</div>';
    }
}