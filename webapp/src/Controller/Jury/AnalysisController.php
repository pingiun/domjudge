<?php declare(strict_types=1);

namespace App\Controller\Jury;

use App\Entity\Judging;
use App\Entity\Problem;
use App\Entity\Submission;
use App\Entity\Team;
use App\Service\DOMJudgeService;
use App\Service\StatisticsService;
use Doctrine\ORM\Query\Expr;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/jury/analysis")
 * @IsGranted("ROLE_JURY")
 */
class AnalysisController extends AbstractController
{
    /**
     * @var DOMJudgeService
     */
    private $dj;

    /**
     * @var StatisticsService
     */
    private $stats;

    public function __construct(DOMJudgeService $dj, StatisticsService $stats)
    {
        $this->dj = $dj;
        $this->stats = $stats;
    }

    /**
     * @Route("", name="analysis_index")
     * @param Request $request
     *
     * @return Response
     */
    public function indexAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $contest = $this->dj->getCurrentContest();

        if ($contest == null) {
            return $this->render('jury/error.html.twig', [
                'error' => 'No contest selected',
            ]);
        }

        $filterKeys = array_keys(StatisticsService::FILTERS);
        $view = $request->query->get('view') ?: reset($filterKeys);

        $problems = $this->stats->getContestProblems($contest);
        $teams = $this->stats->getTeams($contest, $view);
        $misc = $this->stats->getMiscContestStatistics($contest, $teams, $view);

        $maxDelayedJudgings = 10;
        $delayedTimeDiff = 5;
        $delayedJudgings = $em->createQueryBuilder()
            ->from(Submission::class, 's')
            ->innerJoin(Judging::class, 'j', Expr\Join::WITH,
                's.submitid = j.submitid')
            ->select('s.submitid, MIN(j.judgingid) AS judgingid, s.submittime, MIN(j.starttime) - s.submittime AS timediff, COUNT(j.judgingid) AS num_judgings')
            ->andWhere('s.contest = :contest')
            ->setParameter('contest', $contest)
            ->groupBy('s.submitid')
            ->andHaving('timediff > :timediff')
            ->setParameter('timediff', $delayedTimeDiff)
            ->orderBy('timediff', 'DESC')
            ->getQuery()->getResult();

        return $this->render('jury/analysis/contest_overview.html.twig', [
            'contest' => $contest,
            'problems' => $problems,
            'teams' => $teams,
            'submissions' => $misc['submissions'],
            'delayed_judgings' => [
                'data' => array_slice($delayedJudgings, 0, $maxDelayedJudgings),
                'overflow' => count($delayedJudgings) - $maxDelayedJudgings,
                'delay' => $delayedTimeDiff,
            ],
            'misc' => $misc,
            'filters' => StatisticsService::FILTERS,
            'view' => $view,
        ]);
    }

    /**
     * @Route("/team/{teamid}", name="analysis_team")
     * @param Team $team
     *
     * @return Response
     */
    public function teamAction(Team $team)
    {
        $contest = $this->dj->getCurrentContest();

        if ($contest == null) {
            return $this->render('jury/error.html.twig', [
                'error' => 'No contest selected',
            ]);
        }

        return $this->render('jury/analysis/team.html.twig',
            $this->stats->getTeamStats($contest, $team)
        );
    }

    /**
     * @Route("/problem/{probid}", name="analysis_problem")
     * @param Request $request
     * @param Problem $problem
     *
     * @return Response
     */
    public function problemAction(Request $request, Problem $problem)
    {
        $contest = $this->dj->getCurrentContest();

        $filterKeys = array_keys(StatisticsService::FILTERS);
        $view = $request->query->get('view') ?: reset($filterKeys);

        return $this->render('jury/analysis/problem.html.twig',
            $this->stats->getProblemStats($contest, $problem, $view)
        );
    }
}
