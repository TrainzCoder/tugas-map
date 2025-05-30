<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\{On};
new class extends Component {
    public $points = [];
    public $addingPoints = false;
    
    public function mount(): void
    {
        $this->loadPoints();
    }

    public function loadPoints(): void
    {
        $this->points = Auth::user()->locations()->orderBy('created_at', 'asc')->limit(3)->get()->map(fn($loc) => [$loc->latitude, $loc->longitude])->toArray();
    }

    #[On('start-adding')]
    public function startAdding(): void
    {
        Auth::user()->locations()->delete();
        $this->points = [];
        $this->addingPoints = true;
        $this->dispatch('start-adding-js');
    }

    #[On('add-point')]
    public function addPoint($lat, $lng): void
    {
        if (!$this->addingPoints || count($this->points) >= 3) {
            return;
        }

        Auth::user()
            ->locations()
            ->create([
                'latitude' => $lat,
                'longitude' => $lng,
            ]);

        $this->points[] = [$lat, $lng];
        $this->dispatch('point-added-js', points: $this->points);
        if (count($this->points) === 3) {
            $this->addingPoints = false;
            $this->dispatch('polygon-drawn-js', points: $this->points);
        }
    }

    #[On('points-cleared')]
    public function clearPoints(): void
    {
        Auth::user()->locations()->delete();
        $this->points = [];
        $this->addingPoints = false;
        $this->dispatch('points-cleared-js');
    }
}; ?>

<div class="h-full">
    <div class="flex w-full absolute z-[1000] bottom-6 left-[50%] gap-4">
        <flux:button wire:click="startAdding">Add Point</flux:button>
        <flux:button wire:click="clearPoints">Clear Point</flux:button>
    </div>
    <div id="map" class="h-full" wire:ignore></div>
    <input id="searchBox" type="text" placeholder="Search location..." class="absolute top-8 right-[32px] transform bg-[rgba(0,0,0,.5)] backdrop-blur-xs z-[1000] w-[600px] p-2 border rounded">
    
    <script>
        document.addEventListener('livewire:init', () => {
            let map = L.map('map').setView([-6.200000, 106.816666], 15);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(map);

            let markers = [];
            let polygon;
            let currentPoints = [];

            const updateMarkers = (points) => {
                markers.forEach(marker => map.removeLayer(marker));
                if (polygon) map.removeLayer(polygon);

                markers = [];
                polygon = null;
                
                points.forEach(point => {
                    const marker = L.marker(point).addTo(map);
                    markers.push(marker);
                });

                if (points.length === 3) {
                    polygon = L.polygon(points, { color: 'red' }).addTo(map);
                }
            };

            updateMarkers({{ json_encode($points) }});
            currentPoints = {{ json_encode($points) }};

            map.on('click', (e) => {
                if (!Livewire.getByName('addingPoints') || currentPoints.length >= 3) return;
                const { lat, lng } = e.latlng;
                Livewire.dispatch('add-point', { lat, lng });
            });

            Livewire.on('start-adding-js', () => {
                console.log('Start adding points');
                currentPoints = [];
                updateMarkers(currentPoints);
            });

            Livewire.on('point-added-js', (data) => {
                console.log('Point added', data);
                currentPoints = data.points;
                updateMarkers(currentPoints);
            });

            Livewire.on('polygon-drawn-js', (data) => {
                console.log('Polygon drawn', data);
                currentPoints = data.points;
                updateMarkers(currentPoints);
            });

            Livewire.on('points-cleared-js', () => {
                console.log('Points cleared');
                currentPoints = [];
                updateMarkers(currentPoints);
            });

            let searchBox = document.getElementById('searchBox');
            searchBox.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${searchBox.value}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.length > 0) {
                                let latlng = [data[0].lat, data[0].lon];
                                map.setView(latlng, 15);
                                Livewire.dispatch('add-point', { lat: latlng[0], lng: latlng[1] });
                            }
                        });
                }
            });
        });
    </script>
</div>
