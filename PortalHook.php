<?php

/**
 * Send a curl post request after each afterSurveyComplete event
 *
 * @author Rad Cirskis <nad200@gmail.com>
 * @copyright 2023 Evently <https://www.prodata.nz>
 * @license GPL v3
 * @version 1.0.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */
class PortalHook extends PluginBase
{

    protected $storage = 'DbStorage';
    static protected $description = 'Webhook for Limesurvey: send a curl POST after every response submission.';
    static protected $name = 'PortalHook';
    protected $surveyId;

    public function init()
    {
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('afterSurveyDeactivate');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
    }


    protected $settings = array(
        'bUse' => array(
            'type' => 'select',
            'options' => array(
                0 => 'No',
                1 => 'Yes'
            ),
            'default' => 1,
            'label' => 'Send a hook for every survey by default?',
            'help' => 'Overwritable in each Survey setting'
        ),
        'sUrl' => array(
            'type' => 'string',
            'default' => 'https://portal.pmscienceprizes.org.nz/survey/webhooks',
            'label' => 'The default address to send the webhook to',
            'help' => 'If you are using Portal, this should be https://portal.pmscienceprizes.org.nz/survey/webhooks'
        ),
        'sAuthToken' => array(
            'type' => 'string',
            'label' => 'Portal Platform API Token',
            'help' => 'To get a token logon to your account and click on the Tokens tab'
        )
    );

    /**
     * Add setting on survey level: send hook only for certain surveys / url setting per survey / auth code per survey / send user token / send question response
     */
    public function beforeSurveySettings()
    {
        $oEvent = $this->event;
        $oEvent->set("surveysettings.{$this->id}", array(
            'name' => get_class($this),
            'settings' => array(
                'bUse' => array(
                    'type' => 'select',
                    'label' => 'Send a hook for this survey',
                    'options' => array(
                        0 => 'No',
                        1 => 'Yes',
                        2 => 'Use site settings (default)'
                    ),
                    'default' => 2,
                    'help' => 'Leave default to use global setting',
                    'current' => $this->get('bUse', 'Survey', $oEvent->get('survey'))
                ),
                'bUrlOverwrite' => array(
                    'type' => 'select',
                    'label' => 'Overwrite the global Hook Url',
                    'options' => array(
                        0 => 'No',
                        1 => 'Yes'
                    ),
                    'default' => 0,
                    'help' => 'Set to Yes if you want to use a specific URL for this survey',
                    'current' => $this->get('bUrlOverwrite', 'Survey', $oEvent->get('survey'))
                ),
                'sUrl' => array(
                    'type' => 'string',
                    'label' => 'The  address to send the hook for this survey to:',
                    'help' => 'Leave blank to use global setting',
                    'current' => $this->get('sUrl', 'Survey', $oEvent->get('survey'))
                ),
                'bAuthTokenOverwrite' => array(
                    'type' => 'select',
                    'label' => 'Overwrite the global authorization token',
                    'options' => array(
                        0 => 'No',
                        1 => 'Yes'
                    ),
                    'default' => 0,
                    'help' => 'Set to Yes if you want to use a specific zest API token for this survey',
                    'current' => $this->get('bAuthTokenOverwrite', 'Survey', $oEvent->get('survey'))
                ),
                'sAuthToken' => array(
                    'type' => 'string',
                    'label' => 'Use a specific API Token for this survey (leave blank to use default)',
                    'help' => 'Leave blank to use default',
                    'current' => $this->get('sAuthToken', 'Survey', $oEvent->get('survey'))
                ),
                'sAnswersToSend' => array(
                    'type' => 'string',
                    'label' => 'Answers to send',
                    'help' => 'Comma separated question codes of the answers you want to send along',
                    'current' => $this->get('sAnswersToSend', 'Survey', $oEvent->get('survey'))
                ),
                'bDebugMode' => array(
                    'type' => 'select',
                    'options' => array(
                        0 => 'No',
                        1 => 'Yes'
                    ),
                    'default' => 0,
                    'label' => 'Enable Debug Mode',
                    'help' => 'Enable debugmode to see what data is transmitted. Respondents will see this as well so you should turn this off for live surveys',
                    'current' => $this->get('bDebugMode', 'Survey', $oEvent->get('survey')),
                )
            )
        ));
    }

    /**
     * Save the settings
     */
    public function newSurveySettings()
    {
        $event = $this->event;
        foreach ($event->get('settings') as $name => $value) {
            /* In order use survey setting, if not set, use global, if not set use default */
            $default = $event->get($name, null, null, isset($this->settings[$name]['default']) ? $this->settings[$name]['default'] : NULL);
            $this->set($name, $value, 'Survey', $event->get('survey'), $default);
        }
    }


    public function afterSurveyDeactivate()
    {
        $time_start = microtime(true);
        $oEvent     = $this->getEvent();
        $this->surveyId = $oEvent->get('surveyId');
        if ($this->isHookDisabled()) {
            return;
        }
        $url = ($this->get('bUrlOverwrite', 'Survey', $this->surveyId) === '1') ? $this->get('sUrl', 'Survey', $this->surveyId) : $this->get('sUrl', null, null, $this->settings['sUrl']);

        $responseId = $oEvent->get('responseId');
        $response = $this->api->getResponse($this->surveyId, $responseId);
        $sToken = $response['token'];

        $parameters = array(
            "api_token" => $auth,
            "event" => "afterSurveyDeactivate",
            "survey" => $this->surveyId,
            "token" => (isset($sToken)) ? $sToken : null,
            "response" =>  (isset($response)) ? $response : null,
        );

        $hookSent = $this->httpPost($url, $parameters);
        $this->debug($parameters, $hookSent, $time_start,$response);

        return;

    }

    /**
     * Send the webhook on completion of a survey
     * @return array | response
     */
    public function afterSurveyComplete()
    {
        $time_start = microtime(true);
        $oEvent     = $this->getEvent();
        $this->surveyId = $oEvent->get('surveyId');
        if ($this->isHookDisabled()) {
            return;
        }

        $url = ($this->get('bUrlOverwrite', 'Survey', $this->surveyId) === '1') ? $this->get('sUrl', 'Survey', $this->surveyId) : $this->get('sUrl', null, null, $this->settings['sUrl']);
        $auth = ($this->get('bAuthTokenOverwrite', 'Survey', $this->surveyId) === '1') ? $this->get('sAuthToken', 'Survey', $this->surveyId) : $this->get('sAuthToken', null, null, $this->settings['sAuthToken']);
        $additionalFields = $this->getAdditionalFields();

        $responseId = $oEvent->get('responseId');
        $response = $this->api->getResponse($this->surveyId, $responseId);
        $sToken = $response['token'];
        $additionalAnswers = $this->getAdditionalAnswers($additionalFields, $response);

        $parameters = array(
            "event" => "afterSurveyComplete",
            "survey" => $this->surveyId,
            "token" => (isset($sToken)) ? $sToken : null,
            "api_token" => $auth,
            "response" => $response,
            "additionalFields" => ($additionalFields) ? $additionalAnswers : null
        );

        $hookSent = $this->httpPost($url, $parameters);

        $this->debug($parameters, $hookSent, $time_start, $response);

        return;
    }


    /**
     *   httpPost function http://hayageek.com/php-curl-post-get/
     *   creates and executes a POST request
     *   returns the output
     */
    private function httpPost($url, $params)
    {
        $fullUrl = $url . '?api_token=' . $params['api_token'];
        $postData = $params;
        $payload = json_encode( $postData );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        // curl_setopt($ch, CURLOPT_POST, count($payload));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Token ' . $params['api_token']));

        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }


    /**
     *   httpGet
     *   creates and executes a GET request
     *   returns the output
     */
    private function httpGet($url, $params)
    {
        $postData = http_build_query($params, '', '&');
        $fullUrl = $url . '?' . $postData;
        $fp = fopen(dirname(__FILE__) . '/errorlog.txt', 'w');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }

    /**
     *   check if the hook should be sent
     *   returns a boolean
     */

    private function isHookDisabled()
    {
        return ($this->get('bUse', 'Survey', $this->surveyId) == 0) || (($this->get('bUse', 'Survey', $this->surveyId) == 2) && ($this->get('bUse', null, null, $this->settings['bUse']) == 0));
    }


    /**
     *
     *
     */
    private function getAdditionalFields()
    {
        $additionalFieldsString = $this->get('sAnswersToSend', 'Survey', $this->surveyId);
        if ($additionalFieldsString != '' || $additionalFieldsString != null) {
            return explode(',', $this->get('sAnswersToSend', 'Survey', $this->surveyId));
        }
        return null;
    }

    private function getAdditionalAnswers($additionalFields = null, $response = null)
    {
        if ($additionalFields) {
            $additionalAnswers = array();
            foreach ($additionalFields as $field) {
                $additionalAnswers[$field] = htmlspecialchars($response[$field]);
            }
            return $additionalAnswers;
        }
        return null;
    }

    private function debug($parameters, $hookSent, $time_start, $response = null)
    {
        if ($this->get('bDebugMode', 'Survey', $this->surveyId) == 1) {
            $html =  '<pre>';
            $html .= print_r($parameters, true);
            $html .=  "<br><br> ----------------------------- <br><br>";
            $html .= print_r($hookSent, true);
            $html .=  "<br><br> ----------------------------- <br><br>";
            $html .= print_r($response, true);
            $html .=  "<br><br> ----------------------------- <br><br>";
            $html .=  'Total execution time in seconds: ' . (microtime(true) - $time_start);
            $html .=  '</pre>';
            $event = $this->getEvent();
            $event->getContent($this)->addContent($html);
        }
    }
}
