<?php

namespace Flagrow\Byobu\Listeners;

use Carbon\Carbon;
use DateTime;
use Flagrow\Byobu\Events\DiscussionMadePrivate;
use Flagrow\Byobu\Events\DiscussionMadePublic;
use Flagrow\Byobu\Events\DiscussionRecipientsChanged;
use Flarum\Core\Discussion;
use Flarum\Core\Exception\PermissionDeniedException;
use Flarum\Core\Exception\ValidationException;
use Flarum\Core\Repository\UserRepository;
use Flarum\Event\DiscussionWillBeSaved;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\Translation\TranslatorInterface;

class SaveRecipientsToDatabase
{
    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @var Factory
     */
    protected $validator;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var UserRepository
     */
    protected $users;

    /**
     * @param SettingsRepositoryInterface $settings
     * @param Factory $validator
     * @param TranslatorInterface $translator
     * @param UserRepository $users
     */
    public function __construct(
        SettingsRepositoryInterface $settings,
        Factory $validator,
        TranslatorInterface $translator,
        UserRepository $users
    ) {
        $this->settings = $settings;
        $this->validator = $validator;
        $this->translator = $translator;
        $this->users = $users;
    }

    /**
     * @param Dispatcher $events
     */
    public function subscribe(Dispatcher $events)
    {
        $events->listen(DiscussionWillBeSaved::class, [$this, 'whenDiscussionWillBeSaved']);
    }

    /**
     * @param DiscussionWillBeSaved $event
     * @throws PermissionDeniedException
     * @throws ValidationException
     */
    public function whenDiscussionWillBeSaved(DiscussionWillBeSaved $event)
    {
        $discussion = $event->discussion;
        $actor = $event->actor;

        $newUserIds = collect(Arr::get($event->data, 'relationships.recipientUsers.data', []))
            ->map(function ($in) {
                return (int)$in['id'];
            });
        $newGroupIds = collect(Arr::get($event->data, 'relationships.recipientGroups.data', []))
            ->map(function ($in) {
                return (int)$in['id'];
            });

        $addsRecipients = !$newUserIds->isEmpty() || !$newGroupIds->isEmpty();
        $hasRecipients = ($discussion->recipientUsers->count() + $discussion->recipientGroups->count()) > 0;

        if ($discussion->exists && $hasRecipients && !$actor->can('editRecipients', $discussion)) {
            throw new PermissionDeniedException('not allowed to edit recipients');
        } elseif ($addsRecipients && !$discussion->exist && !$actor->hasPermission('createPrivateDiscussions')) {
            throw new PermissionDeniedException('not allowed to create private discussion');
        } elseif ($addsRecipients) {
            // Add the creator to the discussion.
            if ($newGroupIds->isEmpty() && !in_array($actor->id, $newUserIds->all())) {
                $newUserIds->push($actor->id);
            }

            $oldRecipients = [
                'groups' => $discussion->recipientGroups()->get(),
                'users' => $discussion->recipientUsers()->get()
            ];

            // Nothing changed.
            if ($oldRecipients['groups']->pluck('id')->all() == $newGroupIds->all()
                && $oldRecipients['users']->pluck('id')->all() == $newUserIds->all()
            ) {
                return;
            }

            $this->raiseEvent($oldRecipients, $newUserIds, $newGroupIds, $discussion, $actor);

            $discussion->afterSave(function (Discussion $discussion) use ($newGroupIds, $newUserIds, $oldRecipients) {
                foreach (['users', 'groups'] as $type) {
                    $variable = 'new' . Str::ucfirst(Str::singular($type)) . 'Ids';
                    $method = 'recipient' . Str::ucfirst($type);

                    $new = ${$variable};
                    $old = $oldRecipients[$type];

                    $recipients = collect($new->toArray())->merge($old->pluck('id')->toArray())->unique();
                    $discussion->{$method}()->sync($recipients->all());

                    $recipients->each(function ($id) use ($discussion, $method, $new) {
                        if ($new->contains($id)) {
                            $discussion->{$method}()->updateExistingPivot($id, [
                                'removed_at' => null
                            ]);
                        } else {
                            $discussion->{$method}()->updateExistingPivot($id, [
                                'removed_at' => Carbon::now()->format(DateTime::RFC3339)
                            ]);
                        }
                    });
                }
            });
        }
    }

    /**
     * @param $oldRecipients
     * @param $newUserIds
     * @param $newGroupIds
     * @param $discussion
     * @param $actor
     */
    protected function raiseEvent($oldRecipients, $newUserIds, $newGroupIds, $discussion, $actor)
    {
        if ($oldRecipients['users']->isEmpty() && $oldRecipients['groups']->isEmpty()
            && (!$newUserIds->isEmpty() || !$newGroupIds->isEmpty())
        ) {
            $discussion->raise(
                new DiscussionMadePrivate(
                    $discussion,
                    $actor,
                    $newUserIds,
                    $newGroupIds,
                    $oldRecipients['users'],
                    $oldRecipients['groups']
                )
            );
        } elseif (!$oldRecipients['users']->isEmpty() && !$oldRecipients['groups']->isEmpty()
            && ($newUserIds->isEmpty() || $newGroupIds->isEmpty())
        ) {
            $discussion->raise(
                new DiscussionMadePublic(
                    $discussion,
                    $actor,
                    $newUserIds,
                    $newGroupIds,
                    $oldRecipients['users'],
                    $oldRecipients['groups']
                )
            );
        } else {
            $discussion->raise(
                new DiscussionRecipientsChanged(
                    $discussion,
                    $actor,
                    $newUserIds,
                    $newGroupIds,
                    $oldRecipients['users'],
                    $oldRecipients['groups']
                )
            );
        }
    }
}
