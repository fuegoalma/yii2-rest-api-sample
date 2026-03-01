<?php

namespace app\models\dto;

use app\models\db\Album;

readonly class AlbumViewResponse
{
    public function __construct(
        public int $id,
        public string $title,
        public string $first_name,
        public string $last_name,
        public array $photos,
    ) {}

    public static function fromModel(Album $album): self
    {
        return new self(
            id: $album->id,
            title: $album->title,
            first_name: $album->user->first_name,
            last_name: $album->user->last_name,
            photos: $album->photos,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'photos' => array_map(
                fn($photo) => $photo->toArray(),
                $this->photos
            ),
        ];
    }
}