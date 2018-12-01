<?php declare(strict_types=1);

namespace DOMJudgeBundle\Controller\Jury;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DOMJudgeBundle\Entity\Problem;
use DOMJudgeBundle\Entity\Team;
use DOMJudgeBundle\Service\DOMJudgeService;
use DOMJudgeBundle\Service\ScoreboardService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class JuryMiscController
 *
 * @Route("/jury")
 *
 * @package DOMJudgeBundle\Controller\Jury
 */
class JuryMiscController extends Controller
{
    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var DOMJudgeService
     */
    protected $DOMJudgeService;

    /**
     * GeneralInfoController constructor.
     * @param EntityManagerInterface $entityManager
     * @param DOMJudgeService        $DOMJudgeService
     */
    public function __construct(EntityManagerInterface $entityManager, DOMJudgeService $DOMJudgeService)
    {
        $this->entityManager   = $entityManager;
        $this->DOMJudgeService = $DOMJudgeService;
    }

    /**
     * @Route("/", name="jury_index")
     * @Security("has_role('ROLE_JURY') or has_role('ROLE_BALLOON')")
     */
    public function indexAction(Request $request)
    {
        $errors = array();
        if ($this->DOMJudgeService->checkrole('admin')) {
            $result = $this->entityManager->createQueryBuilder()
                ->select('u.username, u.password')
                ->from('DOMJudgeBundle:User', 'u')
                ->join('u.roles', 'r')
                ->andWhere('r.dj_role = :role')
                ->setParameter('role', 'admin')
                ->getQuery()->getResult();
            foreach ($result as $row) {
                if ($row['password'] && password_verify($row['username'], $row['password'])) {
                    $errors[] = "Security alert: the password of the user '"
                        . $row['username'] . "' matches their username. You should change it immediately!";
                }
            }
        }
        return $this->render('DOMJudgeBundle:jury:index.html.twig', ['errors' => $errors]);
    }

    /**
     * @Route("/index.php", name="jury_index_php_redirect")
     */
    public function indexRedirectAction(Request $request)
    {
        return $this->redirectToRoute('jury_index');
    }

    /**
     * @Route("/balloons.php", name="jury_balloons_php_redirect")
     */
    public function balloonsRedirectAction(Request $request)
    {
        return $this->redirectToRoute('jury_balloons');
    }


    /**
     * @Route("/print", methods={"GET"}, name="jury_print")
     * @Security("has_role('ROLE_JURY') or has_role('ROLE_BALLOON')")
     */
    public function printShowAction(Request $request)
    {
        $em       = $this->getDoctrine()->getManager();
        $langs    = $em->getRepository('DOMJudgeBundle:Language')->findAll();
        $langlist = [];
        foreach ($langs as $lang) {
            $langlist[$lang->getLangid()] = $lang->getName();
        }
        asort($langlist);
        return $this->render('DOMJudgeBundle:jury:print.html.twig', ['langlist' => $langlist]);
    }

    /**
     * @Route("/print.php", methods={"GET"}, name="jury_print_php_redirect")
     */
    public function printRedirectAction(Request $request)
    {
        return $this->redirectToRoute('jury_print');
    }

    /**
     * @Route("/updates", methods={"GET"}, name="jury_ajax_updates")
     * @Security("has_role('ROLE_JURY') or has_role('ROLE_BALLOON')")
     */
    public function updatesAction(Request $request)
    {
        return $this->json($this->DOMJudgeService->getUpdates());
    }

    /**
     * @Route("/ajax/{datatype}", methods={"GET"}, name="jury_ajax_data")
     * @param string $datatype
     * @Security("has_role('ROLE_JURY')")
     */
    public function ajaxDataAction(Request $request, string $datatype)
    {
        $q  = $request->query->get('q');
        $qb = $this->entityManager->createQueryBuilder();

        if ($datatype === 'problems') {
            $problems = $qb->from('DOMJudgeBundle:Problem', 'p')
                ->select('p.probid', 'p.name')
                ->where($qb->expr()->like('p.name', '?1'))
                ->orWhere($qb->expr()->eq('p.probid', '?2'))
                ->orderBy('p.name', 'ASC')
                ->getQuery()->setParameter(1, '%' . $q . '%')
                ->setParameter(2, $q)
                ->getResult();

            $results = array_map(function (array $problem) {
                $displayname = $problem['name'] . " (p" . $problem['probid'] . ")";
                return [
                    'id' => $problem['probid'],
                    'text' => $displayname,
                    'search' => $displayname,
                ];
            }, $problems);
        } elseif ($datatype === 'teams') {
            $teams = $qb->from('DOMJudgeBundle:Team', 't')
                ->select('t.teamid', 't.name')
                ->where($qb->expr()->like('t.name', '?1'))
                ->orWhere($qb->expr()->eq('t.teamid', '?2'))
                ->orderBy('t.name', 'ASC')
                ->getQuery()->setParameter(1, '%' . $q . '%')
                ->setParameter(2, $q)
                ->getResult();

            $results = array_map(function (array $team) {
                $displayname = $team['name'] . " (t" . $team['teamid'] . ")";
                return [
                    'id' => $team['teamid'],
                    'text' => $displayname,
                    'search' => $displayname,
                ];
            }, $teams);
        } elseif ($datatype === 'languages') {
            $languages = $qb->from('DOMJudgeBundle:Language', 'l')
                ->select('l.langid', 'l.name')
                ->where($qb->expr()->like('l.name', '?1'))
                ->orWhere($qb->expr()->eq('l.langid', '?2'))
                ->orderBy('l.name', 'ASC')
                ->getQuery()->setParameter(1, '%' . $q . '%')
                ->setParameter(2, $q)
                ->getResult();

            $results = array_map(function (array $language) {
                $displayname = $language['name'] . " (" . $language['langid'] . ")";
                return [
                    'id' => $language['langid'],
                    'text' => $displayname,
                    'search' => $displayname,
                ];
            }, $languages);
        } elseif ($datatype === 'contests') {
            $query = $qb->from('DOMJudgeBundle:Contest', 'c')
                ->select('c.cid', 'c.name', 'c.shortname')
                ->where($qb->expr()->like('c.name', '?1'))
                ->orWhere($qb->expr()->like('c.shortname', '?1'))
                ->orWhere($qb->expr()->eq('c.cid', '?2'))
                ->orderBy('c.name', 'ASC');

            if ($request->query->get('public') !== null) {
                $query = $query->andWhere($qb->expr()->eq('c.public', '?3'));
            }
            $query = $query->getQuery()
                ->setParameter(1, '%' . $q . '%')
                ->setParameter(2, $q);
            if ($request->query->get('public') !== null) {
                $query = $query->setParameter(3, $request->query->get('public'));
            }
            $contests = $query->getResult();

            $results = array_map(function (array $contest) {
                $displayname = $contest['name'] . " (" . $contest['shortname'] . " - c" . $contest['cid'] . ")";
                return [
                    'id' => $contest['cid'],
                    'text' => $displayname,
                    'search' => $displayname,
                ];
            }, $contests);
        } else {
            throw new NotFoundHttpException("Unknown AJAX data type: " . $datatype);
        }

        // TODO: remove this branch and setting of 'search' above when we use select2 exclusively
        if ($request->query->get('select2') ?? false) {
            return $this->json(['results' => $results]);
        } else {
            return $this->json($results);
        }
    }

    /**
     * @Route("/refresh-cache/", name="jury_refresh_cache")
     * @Security("has_role('ROLE_ADMIN')")
     * @param Request           $request
     * @param ScoreboardService $scoreboardService
     * @return \Symfony\Component\HttpFoundation\Response|StreamedResponse
     */
    public function refreshCacheAction(Request $request, ScoreboardService $scoreboardService)
    {
        // Note: we use a XMLHttpRequest here as Symfony does not support streaming Twig outpit

        $contests = $this->DOMJudgeService->getCurrentContests();
        if ($cid = $request->request->get('cid')) {
            if (!isset($contests[$cid])) {
                throw new BadRequestHttpException(sprintf('Contest %s not found', $cid));
            }
            $contests = [$cid => $contests[$cid]];
        } elseif ($request->cookies->has('domjudge_cid') && ($contest = $this->DOMJudgeService->getCurrentContest())) {
            $contests = [$contest->getCid() => $contest];
        }

        if ($request->isXmlHttpRequest() && $request->isMethod('POST')) {
            $progressReporter = function (string $data) {
                echo $data;
                ob_flush();
                flush();
            };
            $response         = new StreamedResponse();
            $response->headers->set('X-Accel-Buffering', 'no');
            $response->setCallback(function () use ($contests, $progressReporter, $scoreboardService) {
                $timeStart = microtime(true);

                $this->DOMJudgeService->auditlog('scoreboard', null, 'refresh cache');

                foreach ($contests as $contest) {
                    $queryBuilder = $this->entityManager->createQueryBuilder()
                        ->from('DOMJudgeBundle:Team', 't')
                        ->select('t')
                        ->orderBy('t.teamid');
                    if (!$contest->getPublic()) {
                        $queryBuilder
                            ->join('t.contests', 'c')
                            ->andWhere('c.cid = :cid')
                            ->setParameter(':cid', $contest->getCid());
                    }
                    /** @var Team[] $teams */
                    $teams = $queryBuilder->getQuery()->getResult();
                    /** @var Problem[] $problems */
                    $problems = $this->entityManager->createQueryBuilder()
                        ->from('DOMJudgeBundle:Problem', 'p')
                        ->join('p.contest_problems', 'cp')
                        ->select('p')
                        ->andWhere('cp.contest = :contest')
                        ->setParameter(':contest', $contest)
                        ->orderBy('cp.shortname')
                        ->getQuery()
                        ->getResult();

                    $message = sprintf('<p>Recalculating all values for the scoreboard cache for contest %d (%d teams, %d problems)...</p>',
                                       $contest->getCid(), count($teams), count($problems));
                    $progressReporter($message);
                    $progressReporter('<pre>');

                    if (count($teams) == 0) {
                        $progressReporter('No teams defined, doing nothing.</pre>');
                        return;
                    }
                    if (count($problems) == 0) {
                        $progressReporter('No problems defined, doing nothing.</pre>');
                        return;
                    }

                    // for each team, fetch the status of each problem
                    foreach ($teams as $team) {
                        $progressReporter(sprintf('Team %d:', $team->getTeamid()));

                        // for each problem fetch the result
                        foreach ($problems as $problem) {
                            $progressReporter(sprintf(' p%d', $problem->getProbid()));
                            $scoreboardService->calculateScoreRow($contest, $team, $problem, false);
                        }

                        $progressReporter(" rankcache\n");
                        $scoreboardService->updateRankCache($contest, $team);
                    }

                    $progressReporter('</pre>');

                    $progressReporter('<p>Deleting irrelevant data...</p>');

                    // Drop all teams and problems that do not exist in the contest
                    if (!empty($problems)) {
                        $problemIds = array_map(function (Problem $problem) {
                            return $problem->getProbid();
                        }, $problems);
                    } else {
                        // problemId -1 will never happen, but otherwise the array is empty and that is not supported
                        $problemIds = [-1];
                    }

                    if (!empty($teams)) {
                        $teamIds = array_map(function (Team $team) {
                            return $team->getTeamid();
                        }, $teams);
                    } else {
                        // teamId -1 will never happen, but otherwise the array is empty and that is not supported
                        $teamIds = [-1];
                    }

                    $params = [
                        ':cid' => $contest->getCid(),
                        ':problemIds' => $problemIds,
                    ];
                    $types  = [
                        ':problemIds' => Connection::PARAM_INT_ARRAY,
                        ':teamIds' => Connection::PARAM_INT_ARRAY,
                    ];
                    $this->entityManager->getConnection()->executeQuery(
                        'DELETE FROM scorecache WHERE cid = :cid AND probid NOT IN (:problemIds)',
                        $params, $types);

                    $params = [
                        ':cid' => $contest->getCid(),
                        ':teamIds' => $teamIds,
                    ];
                    $this->entityManager->getConnection()->executeQuery(
                        'DELETE FROM scorecache WHERE cid = :cid AND teamid NOT IN (:teamIds)',
                        $params, $types);
                    $this->entityManager->getConnection()->executeQuery(
                        'DELETE FROM rankcache WHERE cid = :cid AND teamid NOT IN (:teamIds)',
                        $params, $types);
                }

                $timeEnd = microtime(true);

                $progressReporter(sprintf('<p>Scoreboard cache refresh completed in %.2lf seconds.</p>',
                                          $timeEnd - $timeStart));
            });
            return $response;
        }

        return $this->render('@DOMJudge/jury/refresh_cache.html.twig', [
            'contests' => $contests,
            'contest' => count($contests) === 1 ? reset($contests) : null,
            'doRefresh' => $request->request->has('refresh'),
        ]);
    }
}
