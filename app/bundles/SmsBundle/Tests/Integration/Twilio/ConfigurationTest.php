<?php

namespace Mautic\SmsBundle\Tests\Integration\Twilio;

use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use Mautic\SmsBundle\Integration\Twilio\Configuration;
use Twilio\Exceptions\ConfigurationException;

class ConfigurationTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var IntegrationHelper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $integrationHelper;

    /**
     * @var AbstractIntegration|\PHPUnit\Framework\MockObject\MockObject
     */
    private $integrationObject;

    protected function setUp(): void
    {
        $this->integrationHelper = $this->createMock(IntegrationHelper::class);

        $integrationSettings = new Integration();
        $integrationSettings->setIsPublished(true);
        $integrationSettings->setFeatureSettings(['sending_phone_number' => '123']);
        $integrationSettings->setFeatureSettings(['link_shortening_enabled' => true]);
        $integrationSettings->setFeatureSettings(['messaging_service_sid' => '456']);
        $this->integrationObject = $this->createMock(AbstractIntegration::class);
        $this->integrationObject->method('getIntegrationSettings')
            ->willReturn($integrationSettings);

        $this->integrationHelper->method('getIntegrationObject')
            ->with('Twilio')
            ->willReturn($this->integrationObject);
    }

    public function testGetSendingNumber()
    {
        $this->integrationObject->method('getDecryptedApiKeys')
            ->willReturn(
                [
                    'username' => 'username',
                    'password' => 'password',
                ]
            );
        $this->assertEquals('123', $this->getConfiguration()->getSendingNumber());
    }

    public function testGetAccountSid()
    {
        $this->integrationObject->method('getDecryptedApiKeys')
            ->willReturn(
                [
                    'username' => 'username',
                    'password' => 'password',
                ]
            );
        $this->assertEquals('username', $this->getConfiguration()->getAccountSid());
    }

    public function testGetAuthToken()
    {
        $this->integrationObject->method('getDecryptedApiKeys')
            ->willReturn(
                [
                    'username' => 'username',
                    'password' => 'password',
                ]
            );
        $this->assertEquals('password', $this->getConfiguration()->getAuthToken());
    }

    public function testGetLinkShorteningField() 
    {
        $this->integrationObject->method('isLinkShorteningEnabled')
            ->willReturn(true);
    }

    public function testGetMessagingServiceSid()
    {
        $this->integrationObject->method('getDecryptedApiKeys')
            ->willReturn(
                [
                    'username' => 'username',
                    'password' => 'password',
                ]
            );
        $this->assertEquals('456', $this->getConfiguration()->getMessagingServiceSid());
    }

    public function testConfigurationExceptionThrownWithoutSendingNumber()
    {
        $this->expectException(ConfigurationException::class);

        $this->integrationObject->getIntegrationSettings()->setFeatureSettings(['sending_phone_number' => '']);

        $this->getConfiguration()->getSendingNumber();
    }

    public function testConfigurationExceptionThrownWithoutUsername()
    {
        $this->expectException(ConfigurationException::class);
        $this->integrationObject->method('getDecryptedApiKeys')
            ->willReturn(
                [
                    'username' => '',
                    'password' => 'password',
                ]
            );
        $this->getConfiguration()->getSendingNumber();
    }

    public function testConfigurationExceptionThrownWithoutPassword()
    {
        $this->expectException(ConfigurationException::class);
        $this->integrationObject->method('getDecryptedApiKeys')
            ->willReturn(
                [
                    'username' => 'username',
                    'password' => '',
                ]
            );
        $this->getConfiguration()->getSendingNumber();
    }

    public function testConfigurationExceptionThrownWithoutLinkShorteningValue()
    {
        $this->expectException(ConfigurationException::class);
        $this->integrationObject->method('isLinkShorteningEnabled')
            ->willReturn(True);
        $this->getConfiguration()->isLinkShorteningEnabled();
    }

    public function testConfigurationExceptionThrownWhenLinkShorteningEnabledWithoutMessagingSid()
    {
        $this->expectException(ConfigurationException::class);

        $this->integrationObject->getIntegrationSettings()->setFeatureSettings(['link_shortening_enabled' => True]);
        $this->integrationObject->getIntegrationSettings()->setFeatureSettings(['messaging_service_sid' => '']);

        $this->getConfiguration()->getMessagingServiceSid();
    }

    /**
     * @return Configuration
     */
    private function getConfiguration()
    {
        return new Configuration($this->integrationHelper);
    }
}
