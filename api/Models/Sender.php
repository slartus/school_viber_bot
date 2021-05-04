<?php


class Sender implements JsonSerializable
{
    private string $name;
    private ?string $avatar;

    public function __construct(string $name, ?string $avatar = null)
    {
        $this->name = $name;
        $this->avatar = $avatar;
    }

    public function jsonSerialize(): array
    {
        $data = [
            'name' => $this->name
        ];
        if ($this->avatar) {
            $data["avatar"] = $this->avatar;
        }
        return $data;
    }
}