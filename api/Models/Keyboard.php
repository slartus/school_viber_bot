<?php


class Keyboard implements JsonSerializable
{
    public const INPUT_FIELD_STATE_REGULAR = "regular";
    public const INPUT_FIELD_STATE_HIDDEN = "hidden";
    public const INPUT_FIELD_STATE_MINIMIZED = "minimized";

    private string $inputFieldState = self::INPUT_FIELD_STATE_REGULAR;
    private int $minApiVersion = 1;

    public array $buttons;

    public function __construct(array $buttons)
    {
        $this->buttons = $buttons;
    }

    public function jsonSerialize()
    {
        return [
            "Type" => "keyboard",
            "InputFieldState" => $this->inputFieldState,
            "Buttons" => $this->buttons
        ];
    }

    public function getMinApiVersion(): int
    {
        return $this->minApiVersion;
    }

    public function setInputFieldState(string $inputFieldState)
    {
        $this->inputFieldState = $inputFieldState;
        $this->minApiVersion = 4;
    }
}

class Button
{
    private string $actionBody;
    private string $actionType = "reply";

    public function __construct($actionBody)
    {
    }
}