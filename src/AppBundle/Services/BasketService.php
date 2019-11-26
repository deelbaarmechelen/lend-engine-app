<?php

namespace AppBundle\Services;

use AppBundle\Entity\Contact;
use AppBundle\Entity\Loan;
use AppBundle\Entity\Note;
use AppBundle\Entity\Payment;
use AppBundle\Serializer\Denormalizer\ContactDenormalizer;
use AppBundle\Serializer\Denormalizer\InventoryItemDenormalizer;
use AppBundle\Serializer\Denormalizer\LoanDenormalizer;
use AppBundle\Serializer\Denormalizer\LoanRowDenormalizer;
use AppBundle\Services\Contact\ContactService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

class BasketService
{
    /** @var EntityManagerInterface  */
    private $em;

    /** @var SettingsService */
    private $settings;

    /** @var Session */
    private $session;

    /** @var ContactService */
    private $contactService;

    /** @var Serializer */
    private $serializer;

    /** @var TokenStorageInterface  */
    private $tokenStorage;

    /** @var Contact */
    private $user;

    /** @var EmailService */
    private $emailService;

    /** @var Translator */
    private $translator;

    /** @var Environment  */
    private $twig;

    /** @var array */
    public $errors = [];

    /** @var array */
    public $messages = [];

    /**
     * BasketService constructor.
     * @param EntityManagerInterface $em
     * @param SettingsService $settings
     * @param Session $session
     */
    public function __construct(EntityManagerInterface $em,
                                SettingsService $settings,
                                Session $session,
                                ContactService $contactService,
                                Serializer $serializer,
                                TokenStorageInterface $tokenStorage,
                                EmailService $emailService,
                                Translator $translator,
                                Environment $twig)
    {
        $this->em = $em;
        $this->settings = $settings;
        $this->session = $session;
        $this->contactService = $contactService;
        $this->serializer = $serializer;
        $this->tokenStorage = $tokenStorage;
        $this->emailService = $emailService;
        $this->translator = $translator;
        $this->twig = $twig;

        $this->user = $this->tokenStorage->getToken()->getUser();
    }

    /**
     * @param null $basketContactId
     * @return Loan|bool
     */
    public function createBasket($basketContactId = null)
    {
        // Validation
        if (!$contact = $this->contactService->get($basketContactId)) {
            $this->errors[] = "Couldn't find a contact with ID {$basketContactId}.";
            return false;
        }

        if (!$contact->getActiveMembership()) {
            $this->errors[] = "This member doesn't have an active membership.";
            return false;
        }

        if (!$this->user->hasRole('ROLE_ADMIN')) {
            // We're not admin, we can't create baskets for other people
            if ($contact->getId() != $this->user->getId()) {
                $this->errors[] = "You can't create baskets for other people.";
                return false;
            }
        }

        // Create a basket
        $basket = new Loan();
        $basket->setContact($contact);
        $basket->setCreatedBy($this->user);
        $this->setSessionUser($basketContactId);

        // Only add reservation fee if not admin
        if (!$this->user->hasRole('ROLE_ADMIN')) {
            $bookingFee = $this->settings->getSettingValue('reservation_fee');
            $basket->setReservationFee($bookingFee);
        }

        $this->setSessionUser($basketContactId);

        // Stick it in the session
        $this->setBasket($basket);

        return $basket;
    }

    /**
     * Takes the Basket entity and turns it into JSON for storing in the session
     * @param Loan $basket
     */
    public function setBasket(Loan $basket)
    {
        // ----- Change times from local to UTC ----- //
        if (!$tz = $this->settings->getSettingValue('org_timezone')) {
            $tz = 'Europe/London';
        }
        $timeZone = new \DateTimeZone($tz);
        $utc = new \DateTime('now', new \DateTimeZone("UTC"));
        $offSet = -$timeZone->getOffset($utc)/3600;
        foreach ($basket->getLoanRows() AS $row) {
            /** @var $row \AppBundle\Entity\LoanRow */
            $i = $row->getDueInAt()->modify("{$offSet} hours");
            $row->setDueInAt($i);
            $o = $row->getDueOutAt()->modify("{$offSet} hours");
            $row->setDueOutAt($o);
        }
        // ----- Change times from local to UTC ----- //

        $json = $this->serializer->normalize($basket, null, ['groups' => ['basket']]);
        $this->session->set('basket', $json);
    }

    /**
     * @return Loan|null
     */
    public function getBasket()
    {
        $serializer = new \Symfony\Component\Serializer\Serializer(
            [
                new LoanDenormalizer(),
                new ContactDenormalizer(),
                new LoanRowDenormalizer(),
                new InventoryItemDenormalizer(),
            ], [
                new \Symfony\Component\Serializer\Encoder\JsonDecode()
            ]
        );

        /** @var $basket \AppBundle\Entity\Loan */
        if ($data = $this->session->get('basket')) {

            $basket = $serializer->denormalize($data, Loan::class, 'json');

            // ----- Change times from UTC to local ----- //
            if (!$tz = $this->settings->getSettingValue('org_timezone')) {
                $tz = 'Europe/London';
            }
            $timeZone = new \DateTimeZone($tz);
            $utc = new \DateTime('now', new \DateTimeZone("UTC"));
            $offSet = $timeZone->getOffset($utc)/3600;
            foreach ($basket->getLoanRows() AS $r => $row) {
                /** @var $row \AppBundle\Entity\LoanRow */
                $i = $row->getDueInAt()->modify("{$offSet} hours");
                $row->setDueInAt($i);
                $o = $row->getDueOutAt()->modify("{$offSet} hours");
                $row->setDueOutAt($o);
            }
            // ----- Change times from UTC to local ----- //

            return $basket;
        } else {
            return null;
        }
    }

    /**
     * Turn the basket into a loan on the database
     * @param $action
     * @param $rowFees
     * @return Loan|bool|null
     */
    public function confirmBasket($action, $rowFees)
    {

        /** @var \AppBundle\Repository\InventoryItemRepository $itemRepo */
        $itemRepo = $this->em->getRepository('AppBundle:InventoryItem');

        /** @var \AppBundle\Repository\ContactRepository $contactRepo */
        $contactRepo = $this->em->getRepository('AppBundle:Contact');

        /** @var \AppBundle\Repository\SiteRepository $siteRepo */
        $siteRepo = $this->em->getRepository('AppBundle:Site');

        if (!$user = $this->user) {
            $this->errors[] = "You're not logged in. Please log in and try again.";
            return false;
        }

        // GET THE BASKET
        if (!$basket = $this->getBasket()) {
            $this->errors[] = "Your basket has expired. Please try again.";
            return false;
        }

        if ($action == 'checkout') {
            $basket->setStatus(Loan::STATUS_PENDING);
        } else {
            $action = 'reserve';
            $basket->setStatus(Loan::STATUS_RESERVED);
        }

        // Connect the entities with the DB IDs
        // The basket itself just stores the contact ID
        $contactId = $basket->getContact()->getId();

        $contact = $contactRepo->find($contactId);

        if (!$contact->getActiveMembership()) {
            $this->errors[] = "You don't have an active membership.";
            return false;
        }

        $basket->setContact($contact);
        $basket->setCreatedBy($this->user);

        // ----- Change times from local to UTC ----- //
        if (!$tz = $this->settings->getSettingValue('org_timezone')) {
            $tz = 'Europe/London';
        }
        $timeZone = new \DateTimeZone($tz);
        $utc = new \DateTime('now', new \DateTimeZone("UTC"));
        $offSet = -$timeZone->getOffset($utc)/3600;
        // ----- Change times from local to UTC ----- //

        foreach ($basket->getLoanRows() AS $row) {
            /** @var $row \AppBundle\Entity\LoanRow */

            // Update time zone
            $i = $row->getDueInAt()->modify("{$offSet} hours");
            $row->setDueInAt($i);
            $o = $row->getDueOutAt()->modify("{$offSet} hours");
            $row->setDueOutAt($o);

            // Get the DB entity
            $itemId = $row->getInventoryItem()->getId();
            $item = $itemRepo->find($itemId);
            $row->setInventoryItem($item);

            $siteFromId = $row->getSiteFrom()->getId();
            $siteFrom = $siteRepo->find($siteFromId);
            $row->setSiteFrom($siteFrom);

            $siteToId = $row->getSiteTo()->getId();
            $siteTo = $siteRepo->find($siteToId);
            $row->setSiteTo($siteTo);

            $row->setProductQuantity(1);

            if ($this->user->hasRole('ROLE_ADMIN')) {
                // Allow admins to edit the row fees in the basket UI
                $rowFee = $rowFees[$itemId];
                $row->setFee($rowFee);
            } else {
                // Fee will have been set when creating the basket
            }

            // Connect the detached rows from basket
            $row->setLoan($basket);
            $this->em->persist($row);

            // Update the out time of the reservation
            $basket->setTimeOut($row->getDueOutAt());

            // Also add the item fees if settings require it
            // Only when reserving items, not when checking out
            if ($this->settings->getSettingValue('charge_daily_fee') == 1
                && $row->getFee() > 0
                && $action == 'reserve') {
                $fee = new Payment();
                $fee->setCreatedBy($user);
                $fee->setAmount(-$row->getFee());
                $fee->setContact($basket->getContact());
                $fee->setLoan($basket);
                $fee->setInventoryItem($item);
                $fee->setType(Payment::PAYMENT_TYPE_FEE);
                $this->em->persist($fee);
            }
        }

        $bookingFee = $basket->getReservationFee();

        if ($action == 'checkout') {
            $noteText = 'Loan created by '.$basket->getCreatedBy()->getName();
        } else {
            $noteText = 'Reservation created by '.$basket->getCreatedBy()->getName();
        }

        if ($bookingFee > 0 && $action == 'reserve') {
            $noteText .= "<br>Charged reservation fee of ".number_format($bookingFee, 2).".";
            $fee = new Payment();
            $fee->setCreatedBy($user);
            $fee->setAmount(-$bookingFee);
            $fee->setContact($basket->getContact());
            $fee->setLoan($basket);
            $feeNote = $this->translator->trans('note_reservation_fee', [], 'member_site');
            $fee->setNote($feeNote);
            $this->em->persist($fee);
        }

        $note = new Note();
        $note->setCreatedBy($user);
        $note->setLoan($basket);
        $note->setText($noteText);
        $this->em->persist($note);

        $basket->setTotalFee();
        $basket->setReturnDate();

        $this->em->persist($basket);

        try {
            $this->em->flush();
            $this->session->set('basket', null);
            if ($action == 'checkout') {
                $this->messages[] = "Loan created OK. Now time to check out ...";
            } else {
                $msg = $this->translator->trans('msg_success.reservation_create', [], 'member_site');
                $this->messages[] = $msg;
                $this->sendReservationConfirmEmail($basket->getId());
            }
        } catch (\Exception $generalException) {
            $msg = $this->translator->trans('msg_fail.reservation_create', [], 'member_site');
            $this->errors[] = $msg;
            $this->errors[] = $generalException->getMessage();
        }

        try {
            $this->contactService->recalculateBalance($basket->getContact());
        } catch (\Exception $generalException) {
            $this->errors[] = $generalException->getMessage();
            return false;
        }

        return $basket;

    }

    /**
     * @param $contactId
     */
    public function setSessionUser($contactId) {
        $this->session->set('sessionUserId', $contactId);
    }

    /**
     * @param $loanId
     * @return bool
     */
    private function sendReservationConfirmEmail($loanId) {

        /** @var $loan \AppBundle\Entity\Loan */
        if (!$loan = $this->em->getRepository('AppBundle:Loan')->find($loanId)) {
            $this->errors[] = "Could not find loan ID {$loanId}";
            return false;
        }

        $locale = $loan->getContact()->getLocale();

        // Send email confirmation to the member
        if ($toEmail = $loan->getContact()->getEmail()) {

            $toName = $loan->getContact()->getName();

            if (!$subject = $this->settings->getSettingValue('email_reserve_confirmation_subject')) {
                $subject = $this->translator->trans('le_email.reservation_confirm.subject', [], 'emails', $locale);
            }

            // Save and switch locale for sending the email (it should be the same as the UI anyway)
            $sessionLocale = $this->translator->getLocale();
            $this->translator->setLocale($locale);

            // Build the message content
            $message = $this->twig->render(
                'emails/reservation_confirm.html.twig',
                [
                    'loan' => $loan,
                    'loanRows' => $loan->getLoanRows()
                ]
            );

            // Send the email
            $this->emailService->send($toEmail, $toName, $subject." (Ref ".$loan->getId().")", $message, true);

            // Revert locale for the UI
            $this->translator->setLocale($sessionLocale);
        }

        return true;
    }

}