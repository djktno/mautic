<?php

namespace Mautic\SmsBundle\Integration\Twilio;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use Twilio\Exceptions\ConfigurationException;

class Configuration
{
    /**
     * @var IntegrationHelper
     */
    private $integrationHelper;

    /**
     * @var string
     */
    private $sendingPhoneNumber;

    /**
     * @var string
     */
    private $accountSid;

    /**
     * @var string
     */
    private $messagingServiceSid;

    /**
     * @var string
     */
    private $authToken;

    /**
     * @var bool
     */
    private $linkShorteningEnabled = false;

    /**
     * Configuration constructor.
     */
    public function __construct(IntegrationHelper $integrationHelper)
    {
        $this->integrationHelper = $integrationHelper;
    }

    /**
     * @return string
     *
     * @throws ConfigurationException
     */
    public function getSendingNumber()
    {
        $this->setConfiguration();

        return $this->sendingPhoneNumber;
    }

    /**
     * @return string
     *
     * @throws ConfigurationException
     */
    public function getAccountSid()
    {
        $this->setConfiguration();

        return $this->accountSid;
    }

    /**
     * @return string
     *
     * @throws ConfigurationException
     */
    public function getMessagingServiceSid()
    {
        $this->setConfiguration();

        return $this->messagingServiceSid;
    }

    /**
     * @return string
     *
     * @throws ConfigurationException
     */
    public function getAuthToken()
    {
        $this->setConfiguration();

        return $this->authToken;
    }

    public function isLinkShorteningEnabled()
    {
        $this->setConfiguration();

        return $this->isLinkShorteningEnabled;
    }

    /**
     * @throws ConfigurationException
     */
    private function setConfiguration()
    {
        if ($this->accountSid) {
            return;
        }

        $integration = $this->integrationHelper->getIntegrationObject('Twilio');

        if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            throw new ConfigurationException();
        }

        $this->sendingPhoneNumber = $integration->getIntegrationSettings()->getFeatureSettings()['sending_phone_number'];
        if (empty($this->sendingPhoneNumber)) {
            throw new ConfigurationException();
        }

        $this->isLinkShorteningEnabled = $integration->getIntegrationSettings()->getFeatureSettings()['enable_link_shortening'];
        if (empty($this->isLinkShorteningEnabled)) {
            throw new ConfigurationException();
        }

        $this->messagingServiceSid = $integration->getIntegrationSettings()->getFeatureSettings()['messaging_service_sid'];
        if (empty($this->messagingServiceSid) && $this->isLinkShorteningEnabled) {
            throw new ConfigurationException();
        }

        $keys = $integration->getDecryptedApiKeys();
        if (empty($keys['username']) || empty($keys['password'])) {
            throw new ConfigurationException();
        }

        $this->accountSid = $keys['username'];
        $this->authToken  = $keys['password'];
    }
}
