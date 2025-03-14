<?php

namespace Mautic\LeadBundle\Controller;

use function assert;
use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CoreBundle\Controller\FormController;
use Mautic\CoreBundle\Helper\EmojiHelper;
use Mautic\CoreBundle\Model\IteratorExportDataModel;
use Mautic\LeadBundle\DataObject\LeadManipulator;
use Mautic\LeadBundle\Deduplicate\ContactMerger;
use Mautic\LeadBundle\Deduplicate\Exception\SameContactException;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Form\Type\BatchType;
use Mautic\LeadBundle\Form\Type\DncType;
use Mautic\LeadBundle\Form\Type\EmailType;
use Mautic\LeadBundle\Form\Type\MergeType;
use Mautic\LeadBundle\Form\Type\OwnerType;
use Mautic\LeadBundle\Form\Type\StageType;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use Mautic\LeadBundle\Model\NoteModel;
use Mautic\UserBundle\Model\UserModel;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class LeadController extends FormController
{
    use LeadDetailsTrait;
    use FrequencyRuleTrait;

    /**
     * @param int $page
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction($page = 1)
    {
        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted(
            [
                'lead:leads:viewown',
                'lead:leads:viewother',
                'lead:leads:create',
                'lead:leads:editown',
                'lead:leads:editother',
                'lead:leads:deleteown',
                'lead:leads:deleteother',
                'lead:imports:view',
                'lead:imports:create',
            ],
            'RETURN_ARRAY'
        );

        if (!$permissions['lead:leads:viewown'] && !$permissions['lead:leads:viewother']) {
            return $this->accessDenied();
        }

        $this->setListFilters();

        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model   = $this->getModel('lead');
        $session = $this->get('session');
        //set limits
        $limit = $session->get('mautic.lead.limit', $this->get('mautic.helper.core_parameters')->get('default_pagelimit'));
        $start = (1 === $page) ? 0 : (($page - 1) * $limit);
        if ($start < 0) {
            $start = 0;
        }

        $search = $this->request->get('search', $session->get('mautic.lead.filter', ''));
        $session->set('mautic.lead.filter', $search);

        //do some default filtering
        $orderBy    = $session->get('mautic.lead.orderby', 'l.last_active');
        // Add an id field to orderBy. Prevent Null-value ordering
        $orderById  = 'l.id' !== $orderBy ? ', l.id' : '';
        $orderBy    = $orderBy.$orderById;
        $orderByDir = $session->get('mautic.lead.orderbydir', 'DESC');

        $filter      = ['string' => $search, 'force' => ''];
        $translator  = $this->get('translator');
        $anonymous   = $translator->trans('mautic.lead.lead.searchcommand.isanonymous');
        $listCommand = $translator->trans('mautic.lead.lead.searchcommand.list');
        $mine        = $translator->trans('mautic.core.searchcommand.ismine');
        $indexMode   = $this->request->get('view', $session->get('mautic.lead.indexmode', 'list'));

        $session->set('mautic.lead.indexmode', $indexMode);

        $anonymousShowing = false;
        if ('list' != $indexMode || ('list' == $indexMode && false === strpos($search, $anonymous))) {
            //remove anonymous leads unless requested to prevent clutter
            $filter['force'] .= " !$anonymous";
        } elseif (false !== strpos($search, $anonymous) && false === strpos($search, '!'.$anonymous)) {
            $anonymousShowing = true;
        }

        if (!$permissions['lead:leads:viewother']) {
            $filter['force'] .= " $mine";
        }

        $results = $model->getEntities([
            'start'          => $start,
            'limit'          => $limit,
            'filter'         => $filter,
            'orderBy'        => $orderBy,
            'orderByDir'     => $orderByDir,
            'withTotalCount' => true,
        ]);

        $count = $results['count'];
        unset($results['count']);

        $leads = $results['results'];
        unset($results);

        if ($count && $count < ($start + 1)) {
            //the number of entities are now less then the current page so redirect to the last page
            if (1 === $count) {
                $lastPage = 1;
            } else {
                $lastPage = (ceil($count / $limit)) ?: 1;
            }
            $session->set('mautic.lead.page', $lastPage);
            $returnUrl = $this->generateUrl('mautic_contact_index', ['page' => $lastPage]);

            return $this->postActionRedirect(
                [
                    'returnUrl'       => $returnUrl,
                    'viewParameters'  => ['page' => $lastPage],
                    'contentTemplate' => 'Mautic\LeadBundle\Controller\LeadController::indexAction',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_contact_index',
                        'mauticContent' => 'lead',
                    ],
                ]
            );
        }

        //set what page currently on so that we can return here after form submission/cancellation
        $session->set('mautic.lead.page', $page);

        $tmpl = $this->request->isXmlHttpRequest() ? $this->request->get('tmpl', 'index') : 'index';

        $listArgs = [];
        if (!$this->get('mautic.security')->isGranted('lead:lists:viewother')) {
            $listArgs['filter']['force'] = " $mine";
        }

        $leadListModel = $this->getModel('lead.list');
        assert($leadListModel instanceof ListModel);
        $lists = $leadListModel->getUserLists();

        //check to see if in a single list
        $inSingleList = (1 === substr_count($search, "$listCommand:")) ? true : false;
        $list         = [];
        if ($inSingleList) {
            preg_match("/$listCommand:(.*?)(?=\s|$)/", $search, $matches);

            if (!empty($matches[1])) {
                $alias = $matches[1];
                foreach ($lists as $l) {
                    if ($alias === $l['alias']) {
                        $list = $l;
                        break;
                    }
                }
            }
        }

        // Get the max ID of the latest lead added
        $maxLeadId = $model->getRepository()->getMaxLeadId();

        $leadDNCModel = $this->get('mautic.lead.model.dnc');
        assert($leadDNCModel instanceof \Mautic\LeadBundle\Model\DoNotContact);
        $dncRepository = $leadDNCModel->getDncRepo();

        return $this->delegateView(
            [
                'viewParameters' => [
                    'searchValue'      => $search,
                    'columns'          => $this->get('mautic.lead.columns.dictionary')->getColumns(),
                    'items'            => $leads,
                    'page'             => $page,
                    'totalItems'       => $count,
                    'limit'            => $limit,
                    'permissions'      => $permissions,
                    'tmpl'             => $tmpl,
                    'indexMode'        => $indexMode,
                    'lists'            => $lists,
                    'currentList'      => $list,
                    'security'         => $this->get('mautic.security'),
                    'inSingleList'     => $inSingleList,
                    'noContactList'    => $dncRepository->getChannelList(null, array_keys($leads)),
                    'maxLeadId'        => $maxLeadId,
                    'anonymousShowing' => $anonymousShowing,
                ],
                'contentTemplate' => "MauticLeadBundle:Lead:{$indexMode}.html.twig",
                'passthroughVars' => [
                    'activeLink'    => '#mautic_contact_index',
                    'mauticContent' => 'lead',
                    'route'         => $this->generateUrl('mautic_contact_index', ['page' => $page]),
                ],
            ]
        );
    }

    /**
     * @return JsonResponse|Response
     */
    public function quickAddAction()
    {
        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model = $this->getModel('lead.lead');

        // Get the quick add form
        $action = $this->generateUrl('mautic_contact_action', ['objectAction' => 'new', 'qf' => 1]);

        $fields = $this->getModel('lead.field')->getEntities(
            [
                'filter' => [
                    'force' => [
                        [
                            'column' => 'f.isPublished',
                            'expr'   => 'eq',
                            'value'  => true,
                        ],
                        [
                            'column' => 'f.isShortVisible',
                            'expr'   => 'eq',
                            'value'  => true,
                        ],
                        [
                            'column' => 'f.object',
                            'expr'   => 'like',
                            'value'  => 'lead',
                        ],
                    ],
                ],
                'hydration_mode' => 'HYDRATE_ARRAY',
            ]
        );

        $quickForm = $model->createForm($model->getEntity(), $this->get('form.factory'), $action, ['fields' => $fields, 'isShortForm' => true]);

        //set the default owner to the currently logged in user
        $currentUser = $this->get('security.token_storage')->getToken()->getUser();
        $quickForm->get('owner')->setData($currentUser);

        if ($this->request->isMethod(Request::METHOD_POST)) {
            $quickForm->handleRequest($this->request);
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'quickForm' => $quickForm->createView(),
                ],
                'contentTemplate' => 'MauticLeadBundle:Lead:quickadd.html.twig',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_contact_index',
                    'mauticContent' => 'lead',
                    'route'         => false,
                ],
            ]
        );
    }

    /**
     * Loads a specific lead into the detailed panel.
     *
     * @param $objectId
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function viewAction($objectId)
    {
        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model = $this->getModel('lead.lead');

        // When we change company data these changes get cached
        // so we need to clear the entity manager
        $model->getRepository()->clear();

        /** @var \Mautic\LeadBundle\Entity\Lead $lead */
        $lead = $model->getEntity($objectId);

        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted(
            [
                'lead:leads:viewown',
                'lead:leads:viewother',
                'lead:leads:create',
                'lead:leads:editown',
                'lead:leads:editother',
                'lead:leads:deleteown',
                'lead:leads:deleteother',
            ],
            'RETURN_ARRAY'
        );

        if (null === $lead) {
            //get the page we came from
            $page = $this->get('session')->get('mautic.lead.page', 1);

            //set the return URL
            $returnUrl = $this->generateUrl('mautic_contact_index', ['page' => $page]);

            return $this->postActionRedirect(
                [
                    'returnUrl'       => $returnUrl,
                    'viewParameters'  => ['page' => $page],
                    'contentTemplate' => 'Mautic\LeadBundle\Controller\LeadController::indexAction',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_contact_index',
                        'mauticContent' => 'contact',
                    ],
                    'flashes' => [
                        [
                            'type'    => 'error',
                            'msg'     => 'mautic.lead.lead.error.notfound',
                            'msgVars' => ['%id%' => $objectId],
                        ],
                    ],
                ]
            );
        }

        if (!$this->get('mautic.security')->hasEntityAccess(
            'lead:leads:viewown',
            'lead:leads:viewother',
            $lead->getPermissionUser()
        )
        ) {
            return $this->accessDenied();
        }

        $fields            = $lead->getFields();
        $integrationHelper = $this->get('mautic.helper.integration');
        $socialProfiles    = (array) $integrationHelper->getUserProfiles($lead, $fields);
        $socialProfileUrls = $integrationHelper->getSocialProfileUrlRegex(false);

        $companyModel = $this->getModel('lead.company');
        assert($companyModel instanceof CompanyModel);
        $companiesRepo = $companyModel->getRepository();
        $companies     = $companiesRepo->getCompaniesByLeadId($objectId);
        // Set the social profile templates
        if ($socialProfiles) {
            foreach ($socialProfiles as $integration => &$details) {
                if ($integrationObject = $integrationHelper->getIntegrationObject($integration)) {
                    if ($template = $integrationObject->getSocialProfileTemplate()) {
                        $details['social_profile_template'] = $template;
                    }
                }

                if (!isset($details['social_profile_template'])) {
                    // No profile template found
                    unset($socialProfiles[$integration]);
                }
            }
        }

        // We need the DoNotContact repository to check if a lead is flagged as do not contact
        $dnc = $this->getDoctrine()->getManager()->getRepository('MauticLeadBundle:DoNotContact')->getEntriesByLeadAndChannel($lead, 'email');

        $dncSms = $this->getDoctrine()->getManager()->getRepository('MauticLeadBundle:DoNotContact')->getEntriesByLeadAndChannel($lead, 'sms');

        $integrationRepo = $this->get('doctrine.orm.entity_manager')->getRepository('MauticPluginBundle:IntegrationEntity');

        $model = $this->getModel('lead.list');
        assert($model instanceof ListModel);
        $lists         = $model->getRepository()->getLeadLists([$lead], true, true);
        $leadNoteModel = $this->getModel('lead.note');
        assert($leadNoteModel instanceof NoteModel);

        return $this->delegateView(
            [
                'viewParameters' => [
                    'lead'              => $lead,
                    'avatarPanelState'  => $this->request->cookies->get('mautic_lead_avatar_panel', 'expanded'),
                    'fields'            => $fields,
                    'companies'         => $companies,
                    'lists'             => $lists,
                    'socialProfiles'    => $socialProfiles,
                    'socialProfileUrls' => $socialProfileUrls,
                    'places'            => $this->getPlaces($lead),
                    'permissions'       => $permissions,
                    'events'            => $this->getEngagements($lead),
                    'upcomingEvents'    => $this->getScheduledCampaignEvents($lead),
                    'engagementData'    => $this->getEngagementData($lead),
                    'noteCount'         => $leadNoteModel->getNoteCount($lead, true),
                    'integrations'      => $integrationRepo->getIntegrationEntityByLead($lead->getId()),
                    'devices'           => $this->get('mautic.lead.repository.lead_device')->getLeadDevices($lead),
                    'auditlog'          => $this->getAuditlogs($lead),
                    'doNotContact'      => end($dnc),
                    'doNotContactSms'   => end($dncSms),
                    'leadNotes'         => $this->forward(
                        'Mautic\LeadBundle\Controller\NoteController::indexAction',
                        [
                            'leadId'     => $lead->getId(),
                            'ignoreAjax' => 1,
                        ]
                    )->getContent(),
                ],
                'contentTemplate' => 'MauticLeadBundle:Lead:lead.html.twig',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_contact_index',
                    'mauticContent' => 'lead',
                    'route'         => $this->generateUrl(
                        'mautic_contact_action',
                        [
                            'objectAction' => 'view',
                            'objectId'     => $lead->getId(),
                        ]
                    ),
                ],
            ]
        );
    }

    /**
     * Generates new form and processes post data.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function newAction()
    {
        /** @var LeadModel $model */
        $model = $this->getModel('lead.lead');
        $lead  = $model->getEntity();

        if (!$this->get('mautic.security')->isGranted('lead:leads:create')) {
            return $this->accessDenied();
        }

        //set the page we came from
        $page           = $this->get('session')->get('mautic.lead.page', 1);
        $action         = $this->generateUrl('mautic_contact_action', ['objectAction' => 'new']);
        $leadFieldModel = $this->getModel('lead.field');
        assert($leadFieldModel instanceof FieldModel);
        $fields = $leadFieldModel->getPublishedFieldArrays('lead');
        $form   = $model->createForm($lead, $this->get('form.factory'), $action, ['fields' => $fields]);

        ///Check for a submitted form and process it
        if (Request::METHOD_POST === $this->request->getMethod()) {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    //get custom field values
                    $data = $this->request->request->get('lead');

                    //pull the data from the form in order to apply the form's formatting
                    foreach ($form as $f) {
                        if ('companies' !== $f->getName()) {
                            $data[$f->getName()] = $f->getData();
                        }
                    }

                    $companies = [];
                    if (isset($data['companies'])) {
                        $companies = $data['companies'];
                        unset($data['companies']);
                    }

                    $model->setFieldValues($lead, $data, true);

                    //form is valid so process the data
                    $lead->setManipulator(new LeadManipulator(
                        'lead',
                        'lead',
                        null,
                        $this->get('mautic.helper.user')->getUser()->getName()
                    ));

                    /** @var LeadRepository $contactRepository */
                    $contactRepository = $this->getDoctrine()->getManager()->getRepository(Lead::class);

                    // Save here as we need the entity with an ID for the company code bellow.
                    $contactRepository->saveEntity($lead);

                    if (!empty($companies)) {
                        $model->modifyCompanies($lead, $companies);
                    }

                    // Save here through the model to trigger all subscribers.
                    $model->saveEntity($lead);

                    // Upload avatar if applicable
                    $image = $form['preferred_profile_image']->getData();
                    if ('custom' === $image) {
                        // Check for a file
                        if ($form['custom_avatar']->getData()) {
                            $this->uploadAvatar($lead);
                        }
                    }

                    $identifier = $this->get('translator')->trans($lead->getPrimaryIdentifier());

                    $this->addFlash(
                        'mautic.core.notice.created',
                        [
                            '%name%'      => $identifier,
                            '%menu_link%' => 'mautic_contact_index',
                            '%url%'       => $this->generateUrl(
                                'mautic_contact_action',
                                [
                                    'objectAction' => 'edit',
                                    'objectId'     => $lead->getId(),
                                ]
                            ),
                        ]
                    );

                    $inQuickForm = $this->request->get('qf', false);

                    if ($inQuickForm) {
                        $viewParameters = ['page' => $page];
                        $returnUrl      = $this->generateUrl('mautic_contact_index', $viewParameters);
                        $template       = 'Mautic\LeadBundle\Controller\LeadController::indexAction';
                    } elseif ($this->getFormButton($form, ['buttons', 'save'])->isClicked()) {
                        $viewParameters = [
                            'objectAction' => 'view',
                            'objectId'     => $lead->getId(),
                        ];
                        $returnUrl = $this->generateUrl('mautic_contact_action', $viewParameters);
                        $template  = 'Mautic\LeadBundle\Controller\LeadController::viewAction';
                    } else {
                        return $this->editAction($lead->getId(), true);
                    }
                } else {
                    if ($this->request->get('qf', false)) {
                        return $this->quickAddAction();
                    }

                    $formErrors = $this->getFormErrorMessages($form);
                    $this->addFlash(
                        $this->getFormErrorMessage($formErrors),
                        [],
                        'error'
                    );
                }
            } else {
                $viewParameters = ['page' => $page];
                $returnUrl      = $this->generateUrl('mautic_contact_index', $viewParameters);
                $template       = 'Mautic\LeadBundle\Controller\LeadController::indexAction';
            }

            if ($cancelled || $valid) { //cancelled or success
                return $this->postActionRedirect(
                    [
                        'returnUrl'       => $returnUrl,
                        'viewParameters'  => $viewParameters,
                        'contentTemplate' => $template,
                        'passthroughVars' => [
                            'activeLink'    => '#mautic_contact_index',
                            'mauticContent' => 'lead',
                            'closeModal'    => 1, //just in case in quick form
                        ],
                    ]
                );
            }
        } else {
            //set the default owner to the currently logged in user
            $currentUser = $this->get('security.token_storage')->getToken()->getUser();
            $form->get('owner')->setData($currentUser);
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'form'   => $form->createView(),
                    'lead'   => $lead,
                    'fields' => $model->organizeFieldsByGroup($fields),
                ],
                'contentTemplate' => 'MauticLeadBundle:Lead:form.html.twig',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_contact_index',
                    'mauticContent' => 'lead',
                    'route'         => $this->generateUrl(
                        'mautic_contact_action',
                        [
                            'objectAction' => 'new',
                        ]
                    ),
                ],
            ]
        );
    }

    /**
     * Generates edit form.
     *
     * @param            $objectId
     * @param bool|false $ignorePost
     *
     * @return array|JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function editAction($objectId, $ignorePost = false)
    {
        /** @var LeadModel $model */
        $model = $this->getModel('lead.lead');
        $lead  = $model->getEntity($objectId);

        //set the page we came from
        $page = $this->get('session')->get('mautic.lead.page', 1);

        //set the return URL
        $returnUrl = $this->generateUrl('mautic_contact_index', ['page' => $page]);

        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'viewParameters'  => ['page' => $page],
            'contentTemplate' => 'Mautic\LeadBundle\Controller\LeadController::indexAction',
            'passthroughVars' => [
                'activeLink'    => '#mautic_contact_index',
                'mauticContent' => 'lead',
            ],
        ];
        //lead not found
        if (null === $lead) {
            return $this->postActionRedirect(
                array_merge(
                    $postActionVars,
                    [
                        'flashes' => [
                            [
                                'type'    => 'error',
                                'msg'     => 'mautic.lead.lead.error.notfound',
                                'msgVars' => ['%id%' => $objectId],
                            ],
                        ],
                    ]
                )
            );
        } elseif (!$this->get('mautic.security')->hasEntityAccess(
            'lead:leads:editown',
            'lead:leads:editother',
            $lead->getPermissionUser()
        )
        ) {
            return $this->accessDenied();
        } elseif ($model->isLocked($lead)) {
            //deny access if the entity is locked
            return $this->isLocked($postActionVars, $lead, 'lead.lead');
        }

        $action         = $this->generateUrl('mautic_contact_action', ['objectAction' => 'edit', 'objectId' => $objectId]);
        $leadFieldModel = $this->getModel('lead.field');
        assert($leadFieldModel instanceof FieldModel);
        $fields = $leadFieldModel->getPublishedFieldArrays('lead');
        $form   = $model->createForm($lead, $this->get('form.factory'), $action, ['fields' => $fields]);

        ///Check for a submitted form and process it
        if (!$ignorePost && 'POST' == $this->request->getMethod()) {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    $data = $this->request->request->get('lead');

                    //pull the data from the form in order to apply the form's formatting
                    foreach ($form as $f) {
                        if (('companies' !== $f->getName()) && ('company' !== $f->getName())) {
                            $data[$f->getName()] = $f->getData();
                        }
                    }

                    $companies = [];
                    if (isset($data['companies'])) {
                        $companies = $data['companies'];
                        unset($data['companies']);
                    }
                    $model->setFieldValues($lead, $data, true);

                    //form is valid so process the data
                    $lead->setManipulator(new LeadManipulator(
                        'lead',
                        'lead',
                        $objectId,
                        $this->get('mautic.helper.user')->getUser()->getName()
                    ));
                    $model->modifyCompanies($lead, $companies);
                    $model->saveEntity($lead, $this->getFormButton($form, ['buttons', 'save'])->isClicked());

                    // Upload avatar if applicable
                    $image = $form['preferred_profile_image']->getData();
                    if ('custom' == $image) {
                        // Check for a file
                        /** @var UploadedFile $file */
                        if ($file = $form['custom_avatar']->getData()) {
                            $this->uploadAvatar($lead);

                            // Note the avatar update so that it can be forced to update
                            $this->get('session')->set('mautic.lead.avatar.updated', true);
                        }
                    }

                    $identifier = $this->get('translator')->trans($lead->getPrimaryIdentifier());

                    $this->addFlash(
                        'mautic.core.notice.updated',
                        [
                            '%name%'      => $identifier,
                            '%menu_link%' => 'mautic_contact_index',
                            '%url%'       => $this->generateUrl(
                                'mautic_contact_action',
                                [
                                    'objectAction' => 'edit',
                                    'objectId'     => $lead->getId(),
                                ]
                            ),
                        ]
                    );
                } else {
                    $formErrors = $this->getFormErrorMessages($form);
                    $this->addFlash(
                        $this->getFormErrorMessage($formErrors),
                        [],
                        'error'
                    );
                }
            } else {
                //unlock the entity
                $model->unlockEntity($lead);
            }

            if ($cancelled || ($valid && $this->getFormButton($form, ['buttons', 'save'])->isClicked())) {
                $viewParameters = [
                    'objectAction' => 'view',
                    'objectId'     => $lead->getId(),
                ];

                return $this->postActionRedirect(
                    array_merge(
                        $postActionVars,
                        [
                            'returnUrl'       => $this->generateUrl('mautic_contact_action', $viewParameters),
                            'viewParameters'  => $viewParameters,
                            'contentTemplate' => 'Mautic\LeadBundle\Controller\LeadController::viewAction',
                        ]
                    )
                );
            } elseif ($valid) {
                // Refetch and recreate the form in order to populate data manipulated in the entity itself
                $lead = $model->getEntity($objectId);
                $form = $model->createForm($lead, $this->get('form.factory'), $action, ['fields' => $fields]);
            }
        } else {
            //lock the entity
            $model->lockEntity($lead);
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'form'   => $form->createView(),
                    'lead'   => $lead,
                    'fields' => $lead->getFields(), //pass in the lead fields as they are already organized by ['group']['alias']
                ],
                'contentTemplate' => 'MauticLeadBundle:Lead:form.html.twig',
                'passthroughVars' => [
                    'activeLink'    => '#mautic_contact_index',
                    'mauticContent' => 'lead',
                    'route'         => $this->generateUrl(
                        'mautic_contact_action',
                        [
                            'objectAction' => 'edit',
                            'objectId'     => $lead->getId(),
                        ]
                    ),
                ],
            ]
        );
    }

    /**
     * Upload an asset.
     */
    private function uploadAvatar(Lead $lead)
    {
        $leadInformation = $this->request->files->get('lead', []);
        $file            = $leadInformation['custom_avatar'] ?? null;
        $avatarDir       = $this->get('mautic.helper.template.avatar')->getAvatarPath(true);

        if (!file_exists($avatarDir)) {
            mkdir($avatarDir);
        }

        $file->move($avatarDir, 'avatar'.$lead->getId());

        //remove the file from request
        $this->request->files->remove('lead');
    }

    /**
     * Generates merge form and action.
     *
     * @param $objectId
     *
     * @return array|JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function mergeAction($objectId)
    {
        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model    = $this->getModel('lead');
        $mainLead = $model->getEntity($objectId);
        $page     = $this->get('session')->get('mautic.lead.page', 1);

        //set the return URL
        $returnUrl = $this->generateUrl('mautic_contact_index', ['page' => $page]);

        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'viewParameters'  => ['page' => $page],
            'contentTemplate' => 'Mautic\LeadBundle\Controller\LeadController::indexAction',
            'passthroughVars' => [
                'activeLink'    => '#mautic_contact_index',
                'mauticContent' => 'lead',
            ],
        ];

        if (null === $mainLead) {
            return $this->postActionRedirect(
                array_merge(
                    $postActionVars,
                    [
                        'flashes' => [
                            [
                                'type'    => 'error',
                                'msg'     => 'mautic.lead.lead.error.notfound',
                                'msgVars' => ['%id%' => $objectId],
                            ],
                        ],
                    ]
                )
            );
        }

        //do some default filtering
        $session = $this->get('session');
        $search  = $this->request->get('search', $session->get('mautic.lead.merge.filter', ''));
        $session->set('mautic.lead.merge.filter', $search);
        $leads = [];

        if (!empty($search)) {
            $filter = [
                'string' => $search,
                'force'  => [
                    [
                        'column' => 'l.date_identified',
                        'expr'   => 'isNotNull',
                        'value'  => $mainLead->getId(),
                    ],
                    [
                        'column' => 'l.id',
                        'expr'   => 'neq',
                        'value'  => $mainLead->getId(),
                    ],
                ],
            ];

            $leads = $model->getEntities(
                [
                    'limit'          => 25,
                    'filter'         => $filter,
                    'orderBy'        => 'l.firstname,l.lastname,l.company,l.email',
                    'orderByDir'     => 'ASC',
                    'withTotalCount' => false,
                ]
            );
        }

        $leadChoices = [];
        foreach ($leads as $l) {
            $leadChoices[$l->getPrimaryIdentifier()] = $l->getId();
        }

        $action = $this->generateUrl('mautic_contact_action', ['objectAction' => 'merge', 'objectId' => $mainLead->getId()]);

        $form = $this->get('form.factory')->create(
            MergeType::class,
            [],
            [
                'action' => $action,
                'leads'  => $leadChoices,
            ]
        );

        if ('POST' == $this->request->getMethod()) {
            $valid = true;
            if (!$this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    $data      = $form->getData();
                    $secLeadId = $data['lead_to_merge'];
                    $secLead   = $model->getEntity($secLeadId);

                    if (null === $secLead) {
                        return $this->postActionRedirect(
                            array_merge(
                                $postActionVars,
                                [
                                    'flashes' => [
                                        [
                                            'type'    => 'error',
                                            'msg'     => 'mautic.lead.lead.error.notfound',
                                            'msgVars' => ['%id%' => $secLead->getId()],
                                        ],
                                    ],
                                ]
                            )
                        );
                    } elseif (
                        !$this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $mainLead->getPermissionUser())
                        || !$this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $secLead->getPermissionUser())
                    ) {
                        return $this->accessDenied();
                    } elseif ($model->isLocked($mainLead)) {
                        //deny access if the entity is locked
                        return $this->isLocked($postActionVars, $secLead, 'lead');
                    } elseif ($model->isLocked($secLead)) {
                        //deny access if the entity is locked
                        return $this->isLocked($postActionVars, $secLead, 'lead');
                    }

                    //Both leads are good so now we merge them
                    /** @var ContactMerger $contactMerger */
                    $contactMerger = $this->container->get('mautic.lead.merger');
                    try {
                        $mainLead = $contactMerger->merge($mainLead, $secLead);
                    } catch (SameContactException $exception) {
                    }
                }
            }

            if ($valid) {
                $viewParameters = [
                    'objectId'     => $mainLead->getId(),
                    'objectAction' => 'view',
                ];

                return $this->postActionRedirect(
                    [
                        'returnUrl'       => $this->generateUrl('mautic_contact_action', $viewParameters),
                        'viewParameters'  => $viewParameters,
                        'contentTemplate' => 'Mautic\LeadBundle\Controller\LeadController::viewAction',
                        'passthroughVars' => [
                            'closeModal' => 1,
                        ],
                    ]
                );
            }
        }

        $tmpl = $this->request->get('tmpl', 'index');

        return $this->delegateView(
            [
                'viewParameters' => [
                    'tmpl'         => $tmpl,
                    'leads'        => $leads,
                    'searchValue'  => $search,
                    'action'       => $action,
                    'form'         => $form->createView(),
                    'currentRoute' => $this->generateUrl(
                        'mautic_contact_action',
                        [
                            'objectAction' => 'merge',
                            'objectId'     => $mainLead->getId(),
                        ]
                    ),
                ],
                'contentTemplate' => 'MauticLeadBundle:Lead:merge.html.twig',
                'passthroughVars' => [
                    'route'  => false,
                    'target' => ('update' == $tmpl) ? '.lead-merge-options' : null,
                ],
            ]
        );
    }

    /**
     * Generates contact frequency rules form and action.
     *
     * @param $objectId
     *
     * @return array|JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function contactFrequencyAction($objectId)
    {
        /** @var LeadModel $model */
        $model = $this->getModel('lead');
        $lead  = $model->getEntity($objectId);

        if (null === $lead
            || !$this->get('mautic.security')->hasEntityAccess(
                'lead:leads:editown',
                'lead:leads:editother',
                $lead->getPermissionUser()
            )
        ) {
            return $this->accessDenied();
        }

        $viewParameters = [
            'objectId'     => $lead->getId(),
            'objectAction' => 'view',
        ];

        $form = $this->getFrequencyRuleForm(
            $lead,
            $viewParameters,
            $data,
            false,
            $this->generateUrl('mautic_contact_action', ['objectAction' => 'contactFrequency', 'objectId' => $lead->getId()])
        );

        if (true === $form) {
            return $this->postActionRedirect(
                [
                    'returnUrl' => $this->generateUrl('mautic_contact_action', [
                        'objectId'     => $lead->getId(),
                        'objectAction' => 'view',
                    ]),
                    'viewParameters'  => $viewParameters,
                    'contentTemplate' => 'Mautic\LeadBundle\Controller\LeadController::viewAction',
                    'passthroughVars' => [
                        'closeModal' => 1,
                    ],
                ]
            );
        }

        $tmpl = $this->request->get('tmpl', 'index');

        return $this->delegateView(
            [
                'viewParameters' => array_merge(
                    [
                        'tmpl'         => $tmpl,
                        'form'         => $form->createView(),
                        'currentRoute' => $this->generateUrl(
                            'mautic_contact_action',
                            [
                                'objectAction' => 'contactFrequency',
                                'objectId'     => $lead->getId(),
                            ]
                        ),
                        'lead' => $lead,
                    ],
                    $viewParameters
                ),
                'contentTemplate' => 'MauticLeadBundle:Lead:frequency.html.twig',
                'passthroughVars' => [
                    'route'  => false,
                    'target' => ('update' == $tmpl) ? '.lead-frequency-options' : null,
                ],
            ]
        );
    }

    /**
     * Deletes the entity.
     *
     * @param $objectId
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteAction($objectId)
    {
        $page      = $this->get('session')->get('mautic.lead.page', 1);
        $returnUrl = $this->generateUrl('mautic_contact_index', ['page' => $page]);
        $flashes   = [];

        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'viewParameters'  => ['page' => $page],
            'contentTemplate' => 'Mautic\LeadBundle\Controller\LeadController::indexAction',
            'passthroughVars' => [
                'activeLink'    => '#mautic_contact_index',
                'mauticContent' => 'lead',
            ],
        ];

        if (Request::METHOD_POST === $this->request->getMethod()) {
            $model = $this->getModel('lead.lead');
            assert($model instanceof LeadModel);
            $entity = $model->getEntity($objectId);

            if (null === $entity) {
                $flashes[] = [
                    'type'    => 'error',
                    'msg'     => 'mautic.lead.lead.error.notfound',
                    'msgVars' => ['%id%' => $objectId],
                ];
            } elseif (!$this->get('mautic.security')->hasEntityAccess(
                'lead:leads:deleteown',
                'lead:leads:deleteother',
                $entity->getPermissionUser()
            )
            ) {
                return $this->accessDenied();
            } elseif ($model->isLocked($entity)) {
                return $this->isLocked($postActionVars, $entity, 'lead.lead');
            } else {
                $model->deleteEntity($entity);

                $identifier = $this->get('translator')->trans($entity->getPrimaryIdentifier());
                $flashes[]  = [
                    'type'    => 'notice',
                    'msg'     => 'mautic.core.notice.deleted',
                    'msgVars' => [
                        '%name%' => $identifier,
                        '%id%'   => $objectId,
                    ],
                ];
            }
        } //else don't do anything

        return $this->postActionRedirect(
            array_merge(
                $postActionVars,
                [
                    'flashes' => $flashes,
                ]
            )
        );
    }

    /**
     * Deletes a group of entities.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function batchDeleteAction()
    {
        $page      = $this->get('session')->get('mautic.lead.page', 1);
        $returnUrl = $this->generateUrl('mautic_contact_index', ['page' => $page]);
        $flashes   = [];

        $postActionVars = [
            'returnUrl'       => $returnUrl,
            'viewParameters'  => ['page' => $page],
            'contentTemplate' => 'Mautic\LeadBundle\Controller\LeadController::indexAction',
            'passthroughVars' => [
                'activeLink'    => '#mautic_contact_index',
                'mauticContent' => 'lead',
            ],
        ];

        if (Request::METHOD_POST === $this->request->getMethod()) {
            $model = $this->getModel('lead');
            assert($model instanceof LeadModel);
            $ids       = json_decode($this->request->query->get('ids', '{}'));
            $deleteIds = [];

            // Loop over the IDs to perform access checks pre-delete
            foreach ($ids as $objectId) {
                $entity = $model->getEntity($objectId);

                if (null === $entity) {
                    $flashes[] = [
                        'type'    => 'error',
                        'msg'     => 'mautic.lead.lead.error.notfound',
                        'msgVars' => ['%id%' => $objectId],
                    ];
                } elseif (!$this->get('mautic.security')->hasEntityAccess(
                    'lead:leads:deleteown',
                    'lead:leads:deleteother',
                    $entity->getPermissionUser()
                )
                ) {
                    $flashes[] = $this->accessDenied(true);
                } elseif ($model->isLocked($entity)) {
                    $flashes[] = $this->isLocked($postActionVars, $entity, 'lead', true);
                } else {
                    $deleteIds[] = $objectId;
                }
            }

            // Delete everything we are able to
            if (!empty($deleteIds)) {
                $entities = $model->deleteEntities($deleteIds);

                $flashes[] = [
                    'type'    => 'notice',
                    'msg'     => 'mautic.lead.lead.notice.batch_deleted',
                    'msgVars' => [
                        '%count%' => count($entities),
                    ],
                ];
            }
        } //else don't do anything

        return $this->postActionRedirect(
            array_merge(
                $postActionVars,
                [
                    'flashes' => $flashes,
                ]
            )
        );
    }

    /**
     * Add/remove lead from a list.
     *
     * @param $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function listAction($objectId)
    {
        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model = $this->getModel('lead');
        $lead  = $model->getEntity($objectId);

        if (null != $lead
            && $this->get('mautic.security')->hasEntityAccess(
                'lead:leads:editown',
                'lead:leads:editother',
                $lead->getPermissionUser()
            )
        ) {
            /** @var \Mautic\LeadBundle\Model\ListModel $listModel */
            $listModel = $this->getModel('lead.list');
            $lists     = $listModel->getUserLists();

            // Get a list of lists for the lead
            $leadsLists = $model->getLists($lead, true, true);
        } else {
            $lists = $leadsLists = [];
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'lists'      => $lists,
                    'leadsLists' => $leadsLists,
                    'lead'       => $lead,
                ],
                'contentTemplate' => 'MauticLeadBundle:LeadLists:index.html.twig',
            ]
        );
    }

    /**
     * Add/remove lead from a company.
     *
     * @param $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function companyAction($objectId)
    {
        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model = $this->getModel('lead');
        $lead  = $model->getEntity($objectId);

        if (null != $lead
            && $this->get('mautic.security')->hasEntityAccess(
                'lead:leads:editown',
                'lead:leads:editother',
                $lead->getOwner()
            )
        ) {
            $companyModel = $this->getModel('lead.company');
            assert($companyModel instanceof CompanyModel);
            $companies = $companyModel->getUserCompanies();

            // Get a list of lists for the lead
            $companyLeads = $lead->getCompanies();
            foreach ($companyLeads as $cl) {
                $companyLead[$cl->getId()] = $cl->getId();
            }
        } else {
            $companies = $companyLead = [];
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'companies'   => $companies,
                    'companyLead' => $companyLead,
                    'lead'        => $lead,
                ],
                'contentTemplate' => 'MauticLeadBundle:Lead:company.html.twig',
            ]
        );
    }

    /**
     * Add/remove lead from a campaign.
     *
     * @param $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function campaignAction($objectId)
    {
        $model = $this->getModel('lead');
        $lead  = $model->getEntity($objectId);

        if (null != $lead
            && $this->get('mautic.security')->hasEntityAccess(
                'lead:leads:editown',
                'lead:leads:editother',
                $lead->getPermissionUser()
            )
        ) {
            /** @var \Mautic\CampaignBundle\Model\CampaignModel $campaignModel */
            $campaignModel  = $this->getModel('campaign');
            $campaigns      = $campaignModel->getPublishedCampaigns(true);
            $leadsCampaigns = $campaignModel->getLeadCampaigns($lead, true);

            foreach ($campaigns as $c) {
                $campaigns[$c['id']]['inCampaign'] = (isset($leadsCampaigns[$c['id']])) ? true : false;
            }
        } else {
            $campaigns = [];
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'campaigns' => $campaigns,
                    'lead'      => $lead,
                ],
                'contentTemplate' => 'MauticLeadBundle:LeadCampaigns:index.html.twig',
            ]
        );
    }

    /**
     * @param int $objectId
     *
     * @return JsonResponse
     */
    public function emailAction($objectId = 0)
    {
        $valid = $cancelled = false;

        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model = $this->getModel('lead');

        /** @var \Mautic\LeadBundle\Entity\Lead $lead */
        $lead = $model->getEntity($objectId);

        if (null === $lead
            || !$this->get('mautic.security')->hasEntityAccess(
                'lead:leads:viewown',
                'lead:leads:viewother',
                $lead->getPermissionUser()
            )
        ) {
            return $this->modalAccessDenied();
        }

        $leadFields       = $lead->getProfileFields();
        $leadFields['id'] = $lead->getId();
        $leadEmail        = $leadFields['email'];
        $leadName         = $leadFields['firstname'].' '.$leadFields['lastname'];
        $mailerIsOwner    = $this->get('mautic.helper.core_parameters')->getParameter('mailer_is_owner');

        // Set onwer ID to be the current user ID so it will use his signature
        $leadFields['owner_id'] = $this->get('mautic.helper.user')->getUser()->getId();

        $inList = ('GET' == $this->request->getMethod())
            ? $this->request->get('list', 0)
            : $this->request->request->get(
                'lead_quickemail[list]',
                0,
                true
            );
        $email = ['list' => $inList];

        // Try set owner If should be mailer
        if ($lead->getOwner()) {
            $leadFields['owner_id'] = $lead->getOwner()->getId();
            if ($mailerIsOwner) {
                $email['fromname'] = sprintf(
                    '%s %s',
                    $lead->getOwner()->getFirstName(),
                    $lead->getOwner()->getLastName()
                );
                $email['from'] = $lead->getOwner()->getEmail();
            }
        }

        // Check if lead has a bounce status
        $dnc    = $this->getDoctrine()->getManager()->getRepository('MauticLeadBundle:DoNotContact')->getEntriesByLeadAndChannel($lead, 'email');
        $action = $this->generateUrl('mautic_contact_action', ['objectAction' => 'email', 'objectId' => $objectId]);
        $form   = $this->get('form.factory')->create(EmailType::class, $email, ['action' => $action]);

        if ('POST' == $this->request->getMethod()) {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    $email = $form->getData();

                    $bodyCheck = trim(strip_tags($email['body']));
                    if (!empty($bodyCheck)) {
                        $mailer = $this->get('mautic.helper.mailer')->getMailer();

                        // To lead
                        $mailer->addTo($leadEmail, $leadName);

                        // From user
                        $user = $this->get('mautic.helper.user')->getUser();

                        $mailer->setFrom(
                            $email['from'],
                            empty($email['fromname']) ? null : $email['fromname']
                        );

                        // Set Content
                        $mailer->setBody($email['body']);
                        $mailer->parsePlainText($email['body']);

                        // Set lead
                        $mailer->setLead($leadFields);
                        $mailer->setIdHash();

                        $mailer->setSubject($email['subject']);

                        // Ensure safe emoji for notification
                        $subject = EmojiHelper::toHtml($email['subject']);
                        if ($mailer->send(true, false, false)) {
                            $mailer->createEmailStat();
                            $this->addFlash(
                                'mautic.lead.email.notice.sent',
                                [
                                    '%subject%' => $subject,
                                    '%email%'   => $leadEmail,
                                ]
                            );
                        } else {
                            $errors = $mailer->getErrors();

                            // Unset the array of failed email addresses
                            if (isset($errors['failures'])) {
                                unset($errors['failures']);
                            }

                            $form->addError(
                                new FormError(
                                    $this->get('translator')->trans(
                                        'mautic.lead.email.error.failed',
                                        [
                                            '%subject%' => $subject,
                                            '%email%'   => $leadEmail,
                                            '%error%'   => (is_array($errors)) ? implode('<br />', $errors) : $errors,
                                        ],
                                        'flashes'
                                    )
                                )
                            );
                            $valid = false;
                        }
                    } else {
                        $form['body']->addError(
                            new FormError(
                                $this->get('translator')->trans('mautic.lead.email.body.required', [], 'validators')
                            )
                        );
                        $valid = false;
                    }
                }
            }
        }

        if (empty($leadEmail) || $valid || $cancelled) {
            if ($inList) {
                $route          = 'mautic_contact_index';
                $viewParameters = [
                    'page' => $this->get('session')->get('mautic.lead.page', 1),
                ];
                $func = 'index';
            } else {
                $route          = 'mautic_contact_action';
                $viewParameters = [
                    'objectAction' => 'view',
                    'objectId'     => $objectId,
                ];
                $func = 'view';
            }

            return $this->postActionRedirect(
                [
                    'returnUrl'       => $this->generateUrl($route, $viewParameters),
                    'viewParameters'  => $viewParameters,
                    'contentTemplate' => 'Mautic\LeadBundle\Controller\LeadController::'.$func.'Action',
                    'passthroughVars' => [
                        'mauticContent' => 'lead',
                        'closeModal'    => 1,
                    ],
                ]
            );
        }

        return $this->ajaxAction(
            [
                'contentTemplate' => 'MauticLeadBundle:Lead:email.html.twig',
                'viewParameters'  => [
                    'form' => $form->createView(),
                    'dnc'  => end($dnc),
                ],
                'passthroughVars' => [
                    'mauticContent' => 'leadEmail',
                    'route'         => false,
                ],
            ]
        );
    }

    /**
     * Bulk edit lead campaigns.
     *
     * @param int $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function batchCampaignsAction($objectId = 0)
    {
        /** @var \Mautic\CampaignBundle\Model\CampaignModel $campaignModel */
        $campaignModel = $this->getModel('campaign');

        if ('POST' == $this->request->getMethod()) {
            /** @var \Mautic\LeadBundle\Model\LeadModel $model */
            $model = $this->getModel('lead');
            $data  = $this->request->request->get('lead_batch', [], true);
            $ids   = json_decode($data['ids'], true);

            $entities = [];
            if (is_array($ids)) {
                $entities = $model->getEntities(
                    [
                        'filter' => [
                            'force' => [
                                [
                                    'column' => 'l.id',
                                    'expr'   => 'in',
                                    'value'  => $ids,
                                ],
                            ],
                        ],
                        'ignore_paginator' => true,
                    ]
                );
            }

            foreach ($entities as $key => $lead) {
                if (!$this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $lead->getPermissionUser())) {
                    unset($entities[$key]);
                }
            }

            $add    = (!empty($data['add'])) ? $data['add'] : [];
            $remove = (!empty($data['remove'])) ? $data['remove'] : [];

            if ($count = count($entities)) {
                $campaigns = $campaignModel->getEntities(
                    [
                        'filter' => [
                            'force' => [
                                [
                                    'column' => 'c.id',
                                    'expr'   => 'in',
                                    'value'  => array_merge($add, $remove),
                                ],
                            ],
                        ],
                        'ignore_paginator' => true,
                    ]
                );

                /** @var \Mautic\CampaignBundle\Membership\MembershipManager $membershipManager */
                $membershipManager = $this->get('mautic.campaign.membership.manager');

                if (!empty($add)) {
                    foreach ($add as $cid) {
                        $membershipManager->addContacts(new ArrayCollection($entities), $campaigns[$cid]);
                    }
                }

                if (!empty($remove)) {
                    foreach ($remove as $cid) {
                        $membershipManager->removeContacts(new ArrayCollection($entities), $campaigns[$cid]);
                    }
                }
            }

            $this->addFlash(
                'mautic.lead.batch_leads_affected',
                [
                    '%count%'     => $count,
                ]
            );

            return new JsonResponse(
                [
                    'closeModal' => true,
                    'flashes'    => $this->getFlashContent(),
                ]
            );
        } else {
            // Get a list of campaigns
            $campaigns = $campaignModel->getPublishedCampaigns(true);
            $items     = [];
            foreach ($campaigns as $campaign) {
                $items[$campaign['name']] = $campaign['id'];
            }

            $route = $this->generateUrl(
                'mautic_contact_action',
                [
                    'objectAction' => 'batchCampaigns',
                ]
            );

            return $this->delegateView(
                [
                    'viewParameters' => [
                        'form' => $this->createForm(
                            BatchType::class,
                            [],
                            [
                                'items'  => $items,
                                'action' => $route,
                            ]
                        )->createView(),
                    ],
                    'contentTemplate' => 'MauticLeadBundle:Batch:form.html.twig',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_contact_index',
                        'mauticContent' => 'leadBatch',
                        'route'         => $route,
                    ],
                ]
            );
        }
    }

    /**
     * Bulk add leads to the DNC list.
     *
     * @param int $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function batchDncAction($objectId = 0)
    {
        if ('POST' == $this->request->getMethod()) {
            /** @var \Mautic\LeadBundle\Model\LeadModel $model */
            $model = $this->getModel('lead');

            /** @var \Mautic\LeadBundle\Model\DoNotContact $doNotContact */
            $doNotContact = $this->get('mautic.lead.model.dnc');

            $data = $this->request->request->get('lead_batch_dnc', [], true);
            $ids  = json_decode($data['ids'], true);

            $entities = [];
            if (is_array($ids)) {
                $entities = $model->getEntities(
                    [
                        'filter' => [
                            'force' => [
                                [
                                    'column' => 'l.id',
                                    'expr'   => 'in',
                                    'value'  => $ids,
                                ],
                            ],
                        ],
                        'ignore_paginator' => true,
                    ]
                );
            }

            if ($count = count($entities)) {
                $persistEntities = [];
                foreach ($entities as $lead) {
                    if ($this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $lead->getPermissionUser())) {
                        if ($doNotContact->addDncForContact($lead->getId(), 'email', DoNotContact::MANUAL, $data['reason'])) {
                            $persistEntities[] = $lead;
                        }
                    }
                }

                // Save entities
                $model->saveEntities($persistEntities);
            }

            $this->addFlash(
                'mautic.lead.batch_leads_affected',
                [
                    '%count%'     => $count,
                ]
            );

            return new JsonResponse(
                [
                    'closeModal' => true,
                    'flashes'    => $this->getFlashContent(),
                ]
            );
        } else {
            $route = $this->generateUrl(
                'mautic_contact_action',
                [
                    'objectAction' => 'batchDnc',
                ]
            );

            return $this->delegateView(
                [
                    'viewParameters' => [
                        'form' => $this->createForm(
                            DncType::class,
                            [],
                            [
                                'action' => $route,
                            ]
                        )->createView(),
                    ],
                    'contentTemplate' => 'MauticLeadBundle:Batch:form.html.twig',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_contact_index',
                        'mauticContent' => 'leadBatch',
                        'route'         => $route,
                    ],
                ]
            );
        }
    }

    /**
     * Bulk edit lead stages.
     *
     * @param int $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function batchStagesAction($objectId = 0)
    {
        if ('POST' == $this->request->getMethod()) {
            /** @var \Mautic\LeadBundle\Model\LeadModel $model */
            $model = $this->getModel('lead');
            $data  = $this->request->request->get('lead_batch_stage', [], true);
            $ids   = json_decode($data['ids'], true);

            $entities = [];
            if (is_array($ids)) {
                $entities = $model->getEntities(
                    [
                        'filter' => [
                            'force' => [
                                [
                                    'column' => 'l.id',
                                    'expr'   => 'in',
                                    'value'  => $ids,
                                ],
                            ],
                        ],
                        'ignore_paginator' => true,
                    ]
                );
            }

            $count = 0;
            foreach ($entities as $lead) {
                if ($this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $lead->getPermissionUser())) {
                    ++$count;

                    if (!empty($data['addstage'])) {
                        $stageModel = $this->getModel('stage');

                        $stage = $stageModel->getEntity((int) $data['addstage']);
                        $model->addToStages($lead, $stage);
                    }

                    if (!empty($data['removestage'])) {
                        $stage = $stageModel->getEntity($data['removestage']);
                        $model->removeFromStages($lead, $stage);
                    }
                }
            }
            // Save entities
            $model->saveEntities($entities);
            $this->addFlash(
                'mautic.lead.batch_leads_affected',
                [
                    '%count%'     => $count,
                ]
            );

            return new JsonResponse(
                [
                    'closeModal' => true,
                    'flashes'    => $this->getFlashContent(),
                ]
            );
        } else {
            // Get a list of lists
            /** @var \Mautic\StageBundle\Model\StageModel $model */
            $model  = $this->getModel('stage');
            $stages = $model->getUserStages();
            $items  = [];
            foreach ($stages as $stage) {
                $items[$stage['name']] = $stage['id'];
            }

            $route = $this->generateUrl(
                'mautic_contact_action',
                [
                    'objectAction' => 'batchStages',
                ]
            );

            return $this->delegateView(
                [
                    'viewParameters' => [
                        'form' => $this->createForm(
                            StageType::class,
                            [],
                            [
                                'items'  => $items,
                                'action' => $route,
                            ]
                        )->createView(),
                    ],
                    'contentTemplate' => 'MauticLeadBundle:Batch:form.html.twig',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_contact_index',
                        'mauticContent' => 'leadBatch',
                        'route'         => $route,
                    ],
                ]
            );
        }
    }

    /**
     * Bulk edit lead owner.
     *
     * @param int $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function batchOwnersAction($objectId = 0)
    {
        if ('POST' == $this->request->getMethod()) {
            /** @var \Mautic\LeadBundle\Model\LeadModel $model */
            $model = $this->getModel('lead');
            $data  = $this->request->request->get('lead_batch_owner', [], true);
            $ids   = json_decode($data['ids'], true);

            $entities = [];
            if (is_array($ids)) {
                $entities = $model->getEntities(
                    [
                        'filter' => [
                            'force' => [
                                [
                                    'column' => 'l.id',
                                    'expr'   => 'in',
                                    'value'  => $ids,
                                ],
                            ],
                        ],
                        'ignore_paginator' => true,
                    ]
                );
            }
            $count = 0;
            foreach ($entities as $lead) {
                if ($this->get('mautic.security')->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $lead->getPermissionUser())) {
                    ++$count;

                    if (!empty($data['addowner'])) {
                        $userModel = $this->getModel('user');
                        $user      = $userModel->getEntity((int) $data['addowner']);
                        $lead->setOwner($user);
                    }
                }
            }
            // Save entities
            $model->saveEntities($entities);
            $this->addFlash(
                'mautic.lead.batch_leads_affected',
                [
                    '%count%'     => $count,
                ]
            );

            return new JsonResponse(
                [
                    'closeModal' => true,
                    'flashes'    => $this->getFlashContent(),
                ]
            );
        } else {
            $userModel = $this->getModel('user.user');
            assert($userModel instanceof UserModel);
            $users = $userModel->getRepository()->getUserList('', 0);
            $items = [];
            foreach ($users as $user) {
                $items[$user['firstName'].' '.$user['lastName']] = $user['id'];
            }

            $route = $this->generateUrl(
                'mautic_contact_action',
                [
                    'objectAction' => 'batchOwners',
                ]
            );

            return $this->delegateView(
                [
                    'viewParameters' => [
                        'form' => $this->createForm(
                            OwnerType::class,
                            [],
                            [
                                'items'  => $items,
                                'action' => $route,
                            ]
                        )->createView(),
                    ],
                    'contentTemplate' => 'MauticLeadBundle:Batch:form.html.twig',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_contact_index',
                        'mauticContent' => 'leadBatch',
                        'route'         => $route,
                    ],
                ]
            );
        }
    }

    /**
     * Bulk export contacts.
     *
     * @return array|JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function batchExportAction()
    {
        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted(
            [
                'lead:leads:viewown',
                'lead:leads:viewother',
                'lead:leads:create',
                'lead:leads:editown',
                'lead:leads:editother',
                'lead:leads:deleteown',
                'lead:leads:deleteother',
            ],
            'RETURN_ARRAY'
        );

        if (!$permissions['lead:leads:viewown'] && !$permissions['lead:leads:viewother']) {
            return $this->accessDenied();
        }

        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model      = $this->getModel('lead');
        $session    = $this->get('session');
        $search     = $session->get('mautic.lead.filter', '');
        $orderBy    = $session->get('mautic.lead.orderby', 'l.last_active');
        // Add an id field to orderBy. Prevent Null-value ordering
        $orderById  = 'l.id' !== $orderBy ? ', l.id' : '';
        $orderBy    = $orderBy.$orderById;
        $orderByDir = $session->get('mautic.lead.orderbydir', 'DESC');
        $ids        = $this->request->get('ids');

        $filter     = ['string' => $search, 'force' => ''];
        $translator = $this->get('translator');
        $anonymous  = $translator->trans('mautic.lead.lead.searchcommand.isanonymous');
        $mine       = $translator->trans('mautic.core.searchcommand.ismine');
        $indexMode  = $session->get('mautic.lead.indexmode', 'list');
        $dataType   = $this->request->get('filetype');

        if (!empty($ids)) {
            $filter['force'] = [
                [
                    'column' => 'l.id',
                    'expr'   => 'in',
                    'value'  => json_decode($ids, true),
                ],
            ];
        } else {
            if ('list' != $indexMode || ('list' == $indexMode && false === strpos($search, $anonymous))) {
                //remove anonymous leads unless requested to prevent clutter
                $filter['force'] .= " !$anonymous";
            }

            if (!$permissions['lead:leads:viewother']) {
                $filter['force'] .= " $mine";
            }
        }

        $args = [
            'start'          => 0,
            'limit'          => 200,
            'filter'         => $filter,
            'orderBy'        => $orderBy,
            'orderByDir'     => $orderByDir,
            'withTotalCount' => true,
        ];

        /** @var \Mautic\CoreBundle\Helper\ExportHelper */
        $exportHelper = $this->get('mautic.helper.export');

        $iterator = new IteratorExportDataModel($model, $args, function ($contact) use ($exportHelper) {
            return $exportHelper->parseLeadToExport($contact);
        });

        return $this->exportResultsAs($iterator, $dataType, 'contacts');
    }

    /**
     * @param $contactId
     *
     * @return array|JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function contactExportAction($contactId)
    {
        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted(
            [
                'lead:leads:viewown',
                'lead:leads:viewother',
            ],
            'RETURN_ARRAY'
        );

        if (!$permissions['lead:leads:viewown'] && !$permissions['lead:leads:viewother']) {
            return $this->accessDenied();
        }

        /** @var LeadModel $leadModel */
        $leadModel = $this->getModel('lead.lead');
        $lead      = $leadModel->getEntity($contactId);
        $dataType  = $this->request->get('filetype', 'csv');

        if (empty($lead)) {
            return $this->notFound();
        }

        $contactFields = $lead->getProfileFields();
        $export        = [];
        foreach ($contactFields as $alias => $contactField) {
            $export[] = [
                'alias' => $alias,
                'value' => $contactField,
            ];
        }

        return $this->exportResultsAs($export, $dataType, 'contact_data_'.($contactFields['email'] ?: $contactFields['id']));
    }

    /**
     * Loads a specific lead statistic info.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function contactStatsAction(int $objectId)
    {
        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $model = $this->getModel('lead.lead');

        /** @var \Mautic\LeadBundle\Entity\Lead $lead */
        $lead = $model->getEntity($objectId);

        if (!$this->get('mautic.security')->hasEntityAccess(
            'lead:leads:viewown',
            'lead:leads:viewother',
            $lead->getPermissionUser()
        )
        ) {
            return $this->accessDenied();
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'emailStats' => $model->getLeadEmailStats($lead),
                ],
                'contentTemplate' => 'MauticLeadBundle:Lead:lead_stats.html.twig',
            ]
        );
    }
}
