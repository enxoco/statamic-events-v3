<?php

namespace TransformStudios\Events\Tags;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\CalendarLinks\Link;
use Statamic\Facades\URL;
use Statamic\Support\Arr;
use Statamic\Tags\Collection\Collection as CollectionTag;
use TransformStudios\Events\Calendar;
use TransformStudios\Events\EventFactory;
use TransformStudios\Events\Events as EventsActions;

class Events extends CollectionTag
{
    /**
     * @var Collection|Event
     */
    private $dates;
    private EventsActions $events;
    private $limit;
    private bool $paginated = false;

    private $paginationData;

    public function __construct()
    {
        $this->dates = collect();
        $this->events = new EventsActions;

        Carbon::setWeekStartsAt(Carbon::SUNDAY);
        Carbon::setWeekEndsAt(Carbon::SATURDAY);
    }

    public function between()
    {
        $from = $this->params->pull('from');
        $to = $this->params->pull('to');
        logger("Start between function");

        $this->loadEvents($this->params->bool('collapse_multi_days', false));

        if (! $from) {
            Log::error('Missing `from` parameter in `events:between` tag');

            return;
        }

        if (! $to) {
            Log::error('Missing `to` parameter in `events:between` tag');

            return;
        }

        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->endOfDay();

        $this->loadDates($from, $to);
        
        $dates = array_values(array_merge(
            $this->dates->toArray()
        ));

        
        $page = (int) request('page', 1);
        $this->limit = $this->params->int('limit', 1);

        $this->offset = $this->params->int('offset', 0);


        $this->offset = (($page - 1) * $this->limit) + $this->offset;        
        $paginator = new LengthAwarePaginator(
            items: $dates,
            total: $count = count($dates),
            perPage: $this->limit,
            currentPage: $page
        );

        $paginator
            ->setPath(URL::makeAbsolute(URL::getCurrent()))
            ->appends(request()->all());

        $this->paginationData = [
            'total_items'    => $count,
            'items_per_page' => $this->limit,
            'total_pages'    => $paginator->lastPage(),
            'current_page'   => $paginator->currentPage(),
            'prev_page'      => $paginator->previousPageUrl(),
            'next_page'      => $paginator->nextPageUrl(),
            'auto_links'     => $paginator->render(),
            'links'          => $paginator->render(),
        ];
        $dates = array_slice($dates, $this->offset, $this->limit);
        logger("Dates");

        array_push($dates, array('paginate' => $this->paginationData));
        return $dates;

    }

    public function calendar(): array
    {
        $calendar = new Calendar($this->params->get('collection', config('events.events_collection')));

        return array_values($calendar->month($this->params->get('month'), $this->params->get('year')));
    }

    public function downloadLink(): string
    {
        $event = EventFactory::createFromArray($this->context);

        $from = $event->start();
        $to = $event->end();

        if ($event->isRecurring()) {
            $from->setDateFrom(Carbon::parse($this->params->get('date')));
            $to = $from->copy()->setTimeFromTimeString($event->endTime());
        }

        $title = Arr::get($this->context, 'title');
        $location = Arr::get($this->context, 'location', '');

        $type = $this->params->get('type', 'ics');

        $link = Link::create($title, $from, $to, $event->isAllDay())->address($location);

        return $link->$type();
    }

    public function in(): array
    {
        $this->loadEvents($this->params->bool('collapse_multi_days', false));

        $from = Carbon::now()->startOfDay();
        $to = Carbon::now()->modify($this->params->get('next'))->endOfDay();

        $this->loadDates($from, $to);

        return array_values(array_merge(
            $this->makeEmptyDates($from, $to),
            $this->dates->toArray()
        ));
    }

    public function nowOrParam(): string
    {
        $monthYear = request('month', Carbon::now()->englishMonth).' '.request('year', Carbon::now()->year);

        $month = Carbon::parse($monthYear);

        if ($modify = $this->params->get('modify')) {
            $month->modify($modify);
        }

        return $month->format($this->params->get('format'));
    }

    public function today(): array
    {
        $this->loadEvents($this->params->bool('collapse_multi_days', false));

        $from = $this->params->get('ignore_finished') ? Carbon::now() : Carbon::now()->startOfDay();
        $to = Carbon::now()->endOfDay();

        $this->loadDates($from, $to);

        return array_values(array_merge(
            $this->makeEmptyDates($from, $to),
            $this->dates->toArray()
        ));
    }

    public function upcoming(): array
    {
        logger("running upcoming");
        $this->limit = $this->params->int('limit', 1);

        $this->offset = $this->params->int('offset', 0);

        $this->loadEvents($this->params->bool('collapse_multi_days', false));

        if ($this->params->bool('paginate')) {

            $this->paginate();
        } else {
            $this->dates = $this->events->upcoming($this->limit, $this->offset);
        }

        return $this->outputData();
    }

    protected function paginate(): void
    {
        $this->paginated = true;

        $page = (int) request('page', 1);

        $this->offset = (($page - 1) * $this->limit) + $this->offset;

        if ($this->offset > $this->events->count()) {
            $this->offset = 0;
            $page = 1;
        }

        $events = $this->events->upcoming($this->limit + 1, $this->offset);

        $count = $this->events->count();

        $paginator = new LengthAwarePaginator(
            $events,
            $count,
            $this->limit,
            $page
        );

        $paginator->setPath(URL::makeAbsolute(URL::getCurrent()));
        $paginator->appends(request()->all());

        $this->paginationData = [
            'total_items'    => $count,
            'items_per_page' => $this->limit,
            'total_pages'    => $paginator->lastPage(),
            'current_page'   => $paginator->currentPage(),
            'prev_page'      => $paginator->previousPageUrl(),
            'next_page'      => $paginator->nextPageUrl(),
            'auto_links'     => $paginator->render(),
            'links'          => $paginator->render(),
        ];

        $this->dates = $events->slice(0, $this->limit);
    }
        protected function paginateBetween(Collection $events): void
    {
        $this->paginated = true;

        $page = (int) request('page', 1);

        $paginator = new LengthAwarePaginator(
            items: $events,
            total: $count = $events->count(),
            perPage: $this->limit,
            currentPage: $page
        );

        $paginator
            ->setPath(URL::makeAbsolute(URL::getCurrent()))
            ->appends(request()->all());

        $this->paginationData = [
            'total_items'    => $count,
            'items_per_page' => $this->limit,
            'total_pages'    => $paginator->lastPage(),
            'current_page'   => $paginator->currentPage(),
            'prev_page'      => $paginator->previousPageUrl(),
            'next_page'      => $paginator->nextPageUrl(),
            'auto_links'     => $paginator->render(),
            'links'          => $paginator->render(),
        ];

        $this->dates = $events->slice($this->offset, $this->limit);
    }


    protected function outputData(): array
    {
        $data = array_merge(
            $this->getEventsMetaData(),
            ['dates' => $this->dates->toArray()]
        );

        if ($this->paginated) {
            $data = array_merge($data, ['paginate' => $this->paginationData]);
        }

        return $data;
    }

    private function loadDates($from, $to): void
    {
        $this->dates = $this->events
            ->all($from, $to)
            ->sortBy(fn ($event, $key) => $event->start_date)
            ->map(fn ($days, $key) => [
                    'date' => $key,
                    'dates' => $days->toArray(),
                ]);
    }

    private function loadEvents(bool $collapseMultiDays = false)
    {
        $this->params->put('show_future', true);
        if (! $this->params->has('collection')) {
            $this->params->put('from', 'events');
        }

        // Need to "remove" the limit & paginate, otherwise the `collect` below will limit & paginate the entries.
        // We need to get all the entries, then make the events AND THEN limit & paginate.
        // Didn't use a new parameter because that would break all existing instances and
        // would be a much larger code change.
        // @TODO refactor when move to v3
        $limit = $this->params->pull('limit');

        $paginate = $this->params->pull('paginate', false);

        $events = parent::index();

        if ($limit) {
            $this->limit = $limit;
            $this->params->put('limit', $limit);
        }

        if ($paginate) {
            $this->params->put('paginate', $paginate);
        }

        if ($as = $this->params->get('as')) {
            $events = $events[$as];
        }

        $events->each(
            function ($event) use ($collapseMultiDays) {
                $this->events->add(
                    EventFactory::createFromArray(
                        array_merge(
                            $event->toAugmentedArray(),
                            [
                                'asSingleDay' => $collapseMultiDays,
                            ]
                        )
                    )
                );
            }
        );
    }

    private function makeEmptyDates($from, $to): array
    {
        $dates = [];
        $currentDay = $from = Carbon::parse($from);

        foreach (range(0, Carbon::parse($to)->diffInDays($from)) as $ignore) {
            $date = $currentDay->toDateString();
            $dates[$date] = [
                'date' => $date,
                'no_results' => true,
            ];
            $currentDay->addDay();
        }

        return $dates;
    }

    protected function getEventsMetaData(): array
    {
        return [
            'no_results' => $this->dates->isEmpty(),
            'total_results' => $this->dates->count(),
        ];
    }
}
