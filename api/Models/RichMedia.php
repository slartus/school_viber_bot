<?php

class RichMedia implements JsonSerializable
{

    private array $buttons;

    public function __construct(array $buttons)
    {
        $this->buttons = $buttons;
    }

    public function jsonSerialize(): array
    {
        $data = [
            'Type' => "rich_media",
            "ButtonsGroupColumns"=>1,
            "ButtonsGroupRows"=>1,
            "Buttons" => $this->buttons
        ];
        return $data;
    }
}