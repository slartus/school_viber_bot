<?php
require("Models/Message.php");
require("Models/Keyboard.php");

class ViberApi
{
    private string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function getWebHookResponse(): array
    {
        return [
            "status" => 0,
            "status_message" => "ok",
            "event_type" => "delivered"
        ];
    }

    public function getWelcomeMessage($senderName, $welcomeMessage): array
    {
        return [
            "sender" => [
                "name" => $senderName
            ],
            "type" => "text",
            "text" => $welcomeMessage,
        ];
    }

    public function getSeenResponse($data): array
    {
        $senderId = $data["sender"]["id"];
        $messageToken = $data["message_token"];
        return [
            "event" => "seen",
            "timestamp" => time() * 1000,
            "message_token" => $messageToken,
            "user_id" => $senderId
        ];
    }

    public function sendMessage(Message $message)
    {
        $data = $message->jsonSerialize();
        return $this->sendRawMessage($data);
    }

    private function sendRawMessage($data)
    {
        $url = "https://chatapi.viber.com/pa/send_message";
        $data["auth_token"] = $this->token;
        return $this->postData($url, $data);
    }

    public function sendBroadcastMessage(BroadcastMessage $message)
    {
        $data = $message->jsonSerialize();
        return $this->sendRawBroadcastMessage($data);
    }

    private function sendRawBroadcastMessage($data)
    {
        $url = "https://chatapi.viber.com/pa/broadcast_message";
        $data["auth_token"] = $this->token;
        return $this->postData($url, $data);
    }

    public function getAccountInfo(): string
    {
        $url = "https://chatapi.viber.com/pa/get_account_info";
        $data = array(
            "min_api_version" => 1,
            "auth_token" => $this->token
        );
        return $this->postData($url, $data);
    }

    public function getUserDetails(string $viberId): string
    {
        $url = "https://chatapi.viber.com/pa/get_user_details";
        $data = array(
            "min_api_version" => 1,
            "auth_token" => $this->token,
            "id" => $viberId
        );
        return $this->postData($url, $data);
    }

    private function postData(string $url, array $data): string
    {
        $jsonData = json_encode($data);
        return $this->postJsonData($url, $jsonData);
    }

    public function postJsonData(string $url, string $jsonData): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        return curl_exec($ch);
    }
}
