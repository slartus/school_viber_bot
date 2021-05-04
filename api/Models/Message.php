<?php

require "Sender.php";
require "RichMedia.php";

abstract class Message implements JsonSerializable
{

    const TYPE_TEXT = "text";
    const TYPE_RICH_MEDIA = "rich_media";


    /**
     * @var string Message type. Available message types: text, picture, video, file, location, contact, sticker, carousel content and url
     */
    private string $type;
    private Sender $sender;
    private ?string $trackingData = null;
    private ?Keyboard $keyboard = null;
    protected int $minApiVersion = 1;

    public function __construct(string $type, Sender $sender)
    {
        $this->type = $type;
        $this->sender = $sender;
    }

    public function setTrackingData(?string $trackingData)
    {
        $this->trackingData = $trackingData;
    }

    public function setKeyboard(?Keyboard $keyboard)
    {
        $this->keyboard = $keyboard;
    }

    public function getMinApiVersion(): int
    {
        $minApiVersion = $this->minApiVersion;
        if ($this->keyboard) {
            $minApiVersion = max($minApiVersion, $this->keyboard->getMinApiVersion());
        }
        return $minApiVersion;
    }

    public function jsonSerialize(): array
    {
        $data = [
            'type' => $this->type,
            'sender' => $this->sender->jsonSerialize(),
            'min_api_version' => $this->getMinApiVersion()
        ];
        if ($this->trackingData) {
            $data["tracking_data"] = $this->trackingData;
        }
        if ($this->keyboard) {
            $data["keyboard"] = $this->keyboard->jsonSerialize();
        }
        return $data;
    }
}

class TextMessage extends Message
{
    private string $text;
    /**
     * Unique Viber user id
     * @var string Unique Viber user id
     */
    private string $receiver;

    public function __construct(string $receiver, Sender $sender, string $text)
    {
        parent::__construct(Message::TYPE_TEXT, $sender);
        $this->receiver = $receiver;
        $this->text = $text;
    }

    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();

        $data["text"] = $this->text;
        $data["receiver"] = $this->receiver;

        return $data;
    }
}

class BroadcastMessage extends Message
{
    private string $text;
    /**
     * Unique Viber user ids
     */
    private array $receivers;

    public function __construct(array $receivers, Sender $sender, string $text)
    {
        parent::__construct(Message::TYPE_TEXT, $sender);
        $this->receivers = $receivers;
        $this->text = $text;
    }

    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();

        $data["text"] = $this->text;
        $data["broadcast_list"] = $this->receivers;
        return $data;
    }
}

class RichMediaMessage extends Message
{
    /**
     * Unique Viber user id
     */
    private string $receiver;
    private RichMedia $richMedia;

    public function __construct(string $receiver, Sender $sender, RichMedia $richMedia)
    {
        parent::__construct(Message::TYPE_RICH_MEDIA, $sender);
        $this->minApiVersion = 7;
        $this->receiver = $receiver;
        $this->richMedia = $richMedia;
    }

    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();

        $data["receiver"] = $this->receiver;

        $data["rich_media"] = $this->richMedia->jsonSerialize();
        return $data;
    }
}